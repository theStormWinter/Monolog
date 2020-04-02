<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.md that was distributed with this source code.
 */

namespace Kdyby\Monolog\DI;

use Kdyby\Monolog\Logger as KdybyLogger;
use Kdyby\Monolog\Processor\PriorityProcessor;
use Kdyby\Monolog\Processor\TracyExceptionProcessor;
use Kdyby\Monolog\Processor\TracyUrlProcessor;
use Kdyby\Monolog\Tracy\BlueScreenRenderer;
use Kdyby\Monolog\Tracy\MonologAdapter;
use Kdyby\StrictObjects\Scream;
use Nette\Configurator;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Definition;
use Nette\DI\Helpers as DIHelpers;
use Nette\PhpGenerator\ClassType as ClassTypeGenerator;
use Nette\PhpGenerator\PhpLiteral;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;
use Tracy\Debugger;
use Tracy\ILogger;


/**
 * Integrates the Monolog seamlessly into your Nette Framework application.
 */
class MonologExtension extends CompilerExtension
{

    use Scream;

    const TAG_HANDLER = 'monolog.handler';
    const TAG_PROCESSOR = 'monolog.processor';
    const TAG_PRIORITY = 'monolog.priority';

    /** @var array */
    protected $config = [];

    /**
     * @return Schema
     */
    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'handlers'             => Expect::anyOf(Expect::arrayOf('Nette\DI\Definitions\Statement'), 'false')->default([]),
            'processors'           => Expect::anyOf(Expect::arrayOf('Nette\DI\Definitions\Statement'), 'false')->default([]),
            'name'                 => Expect::string('app'),
            'hookToTracy'          => Expect::bool(true),
            'tracyBaseUrl'         => Expect::string(),
            'usePriorityProcessor' => Expect::bool(true),
            'accessPriority'       => Expect::string(ILogger::INFO),
            'logDir'               => Expect::string(),
        ])->castTo('array');
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();
        $this->config['logDir'] = self::resolveLogDir($builder->parameters);
        $config = $this->config;
        self::createDirectory($config['logDir']);

        if (!isset($builder->parameters[$this->name]) || (is_array($builder->parameters[$this->name]) && !isset($builder->parameters[$this->name]['name']))) {
            $builder->parameters[$this->name]['name'] = $config['name'];
        }

        if (!isset($builder->parameters['logDir'])) { // BC
            $builder->parameters['logDir'] = $config['logDir'];
        }

        $builder->addDefinition($this->prefix('logger'))
            ->setFactory(KdybyLogger::class, [$config['name']]);

        // Tracy adapter
        $builder->addDefinition($this->prefix('adapter'))
            ->setFactory(MonologAdapter::class, [
                'monolog'            => $this->prefix('@logger'),
                'blueScreenRenderer' => $this->prefix('@blueScreenRenderer'),
                'email'              => Debugger::$email,
                'accessPriority'     => $config['accessPriority'],
            ])
            ->addTag('logger');

        // The renderer has to be separate, to solve circural service dependencies
        $builder->addDefinition($this->prefix('blueScreenRenderer'))
            ->setFactory(BlueScreenRenderer::class, [
                'directory' => $config['logDir'],
            ])
            ->setAutowired(false)
            ->addTag('logger');

        if ($config['hookToTracy'] === true && $builder->hasDefinition('tracy.logger')) {
            // TracyExtension initializes the logger from DIC, if definition is changed
            $builder->removeDefinition($existing = 'tracy.logger');
            $builder->addAlias($existing, $this->prefix('adapter'));
        }

        $this->loadHandlers($config);
        $this->loadProcessors($config);
        $this->setConfig($config);
    }

    protected function loadHandlers(array $config): void
    {
        $builder = $this->getContainerBuilder();

        foreach ($config['handlers'] as $handlerName => $implementation) {

            $builder->addDefinition($this->prefix('handler.'.$handlerName))
                ->setFactory($implementation)
                ->setAutowired(false)
                ->addTag(self::TAG_HANDLER)
                ->addTag(self::TAG_PRIORITY, is_numeric($handlerName) ? $handlerName : 0);
        }
    }

    protected function loadProcessors(array $config): void
    {
        $builder = $this->getContainerBuilder();

        if ($config['usePriorityProcessor'] === true) {
            // change channel name to priority if available
            $builder->addDefinition($this->prefix('processor.priorityProcessor'))
                ->setFactory(PriorityProcessor::class)
                ->addTag(self::TAG_PROCESSOR)
                ->addTag(self::TAG_PRIORITY, 20);
        }

        $builder->addDefinition($this->prefix('processor.tracyException'))
            ->setFactory(TracyExceptionProcessor::class, [
                'blueScreenRenderer' => $this->prefix('@blueScreenRenderer'),
            ])
            ->addTag(self::TAG_PROCESSOR)
            ->addTag(self::TAG_PRIORITY, 100);

        if ($config['tracyBaseUrl'] !== null) {
            $builder->addDefinition($this->prefix('processor.tracyBaseUrl'))
                ->setFactory(TracyUrlProcessor::class, [
                    'baseUrl'            => $config['tracyBaseUrl'],
                    'blueScreenRenderer' => $this->prefix('@blueScreenRenderer'),
                ])
                ->addTag(self::TAG_PROCESSOR)
                ->addTag(self::TAG_PRIORITY, 10);
        }

        foreach ($config['processors'] as $processorName => $implementation) {

            $this->compiler->loadDefinitionsFromConfig([
                $serviceName = $this->prefix('processor.'.$processorName) => $implementation,
            ]);

            $builder->getDefinition($serviceName)
                ->addTag(self::TAG_PROCESSOR)
                ->addTag(self::TAG_PRIORITY, is_numeric($processorName) ? $processorName : 0);
        }
    }

    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();
        /** @var Definition $logger */
        $logger = $builder->getDefinition($this->prefix('logger'));

        foreach ($handlers = $this->findByTagSorted(self::TAG_HANDLER) as $serviceName => $meta) {
            $logger->addSetup('pushHandler', ['@'.$serviceName]);
        }

        foreach ($this->findByTagSorted(self::TAG_PROCESSOR) as $serviceName => $meta) {
            $logger->addSetup('pushProcessor', ['@'.$serviceName]);
        }


        /** @var Definition $service */
        foreach ($builder->findByType(LoggerAwareInterface::class) as $service) {
            $service->addSetup('setLogger', ['@'.$this->prefix('logger')]);
        }
    }

    protected function findByTagSorted($tag): array
    {
        $builder = $this->getContainerBuilder();

        $services = $builder->findByTag($tag);
        uksort($services, function($nameA, $nameB) use ($builder) {
            $pa = $builder->getDefinition($nameA)->getTag(self::TAG_PRIORITY) ? : 0;
            $pb = $builder->getDefinition($nameB)->getTag(self::TAG_PRIORITY) ? : 0;

            return $pa > $pb ? 1 : ($pa < $pb ? -1 : 0);
        });

        return $services;
    }

    public function afterCompile(ClassTypeGenerator $class): void
    {
        $initialize = $class->getMethod('initialize');

        if (Debugger::$logDirectory === null && array_key_exists('logDir', $this->config)) {
            $initialize->addBody('?::$logDirectory = ?;', [new PhpLiteral(Debugger::class), $this->config['logDir']]);
        }
    }

    public static function register(Configurator $configurator): void
    {
        $configurator->onCompile[] = function($config, Compiler $compiler) {
            $compiler->addExtension('monolog', new MonologExtension);
        };
    }

    /**
     * @return string
     */
    private static function resolveLogDir(array $parameters): string
    {
        if (isset($parameters['logDir'])) {
            return DIHelpers::expand('%logDir%', $parameters);
        }

        if (Debugger::$logDirectory !== null) {
            return Debugger::$logDirectory;
        }

        return DIHelpers::expand('%appDir%/../log', $parameters);
    }

    /**
     * @param string $logDir
     */
    private static function createDirectory($logDir): void
    {
        if (!@mkdir($logDir, 0777, true) && !is_dir($logDir)) {
            throw new RuntimeException(sprintf('Log dir %s cannot be created', $logDir));
        }
    }

}
