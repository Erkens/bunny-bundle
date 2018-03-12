<?php

namespace Skrz\Bundle\BunnyBundle\DependencyInjection\Compiler;

use Doctrine\Common\Annotations\AnnotationReader;
use Skrz\Bundle\BunnyBundle\Annotation\Consumer;
use Skrz\Bundle\BunnyBundle\Annotation\Producer;
use Skrz\Bundle\BunnyBundle\BunnyException;
use Skrz\Bundle\BunnyBundle\ContentTypes;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;

class BunnyCompilerPass implements CompilerPassInterface
{

    /** @var string */
    private $configKey;

    /** @var string */
    private $clientServiceId;

    /** @var string */
    private $managerServiceId;

    /** @var string */
    private $channelServiceId;

    /** @var string */
    private $setupCommandServiceId;

    /** @var string */
    private $consumerCommandServiceId;

    /** @var string */
    private $producerCommandServiceId;

    /** @var AnnotationReader */
    private $annotationReader;

    public function __construct(
        $configKey,
        $clientServiceId,
        $managerServiceId,
        $channelServiceId,
        $setupCommandServiceId,
        $consumerCommandServiceId,
        $producerCommandServiceId,
        AnnotationReader $annotationReader
    )
    {
        $this->configKey = $configKey;
        $this->clientServiceId = $clientServiceId;
        $this->managerServiceId = $managerServiceId;
        $this->channelServiceId = $channelServiceId;
        $this->setupCommandServiceId = $setupCommandServiceId;
        $this->consumerCommandServiceId = $consumerCommandServiceId;
        $this->producerCommandServiceId = $producerCommandServiceId;
        $this->annotationReader = $annotationReader;
    }


    /**
     * @param ContainerBuilder $container
     * @throws BunnyException
     */
    public function process(ContainerBuilder $container)
    {
        if (!$container->hasParameter($this->configKey)) {
            throw new \InvalidArgumentException("Container doesn't have parameter '{$this->configKey}', SkrzBunnyExtension probably haven't processed config.");
        }

        $config = $container->getParameter($this->configKey);

        $consumers = [];
        $producers = [];

        foreach (array_merge($config['producers'], $config['consumers']) as $serviceName) {

            try {
                $service = $container->get($serviceName);
            } catch (ServiceNotFoundException $serviceNotFoundException) {
                throw new BunnyException("Service not found: " . $serviceName, 0, $serviceNotFoundException);
            } catch (\Exception $exception) {
                throw new BunnyException("Unknown exception: " . $exception->getMessage(), $exception->getCode(), $exception);
            }
            $rc = new \ReflectionObject($service);

            foreach ($this->annotationReader->getClassAnnotations($rc) as $annotation) {
                if ($annotation instanceof Consumer) {
                    if (empty($annotation->queue) === empty($annotation->exchange)) {
                        throw new BunnyException(
                            "Either 'queue', or 'exchange' (but not both) has to be specified (service: {$serviceName})."
                        );
                    }

                    if (!isset($consumers[$serviceName])) {
                        $consumers[$serviceName] = [];
                    }

                    $consumers[$serviceName][] = (array)$annotation;

                } elseif ($annotation instanceof Producer) {

                    if (empty($annotation->contentType)) {
                        $annotation->contentType = ContentTypes::APPLICATION_JSON;
                    }

                    $producers[$serviceName] = (array)$annotation;
                }
            }
        }

        $container->setDefinition($this->clientServiceId, new Definition("Bunny\\Client", [[
            "host" => $config["host"],
            "port" => $config["port"],
            "vhost" => $config["vhost"],
            "user" => $config["user"],
            "password" => $config["password"],
            "heartbeat" => $config["heartbeat"],
        ]]));

        $container->setDefinition($this->managerServiceId, new Definition("Skrz\\Bundle\\BunnyBundle\\BunnyManager", [
            new Reference("service_container"),
            $this->clientServiceId,
            $config,
        ]));

        $channel = new Definition("Bunny\\Channel");
        $channel->setFactory([new Reference($this->managerServiceId), "getChannel"]);
        $container->setDefinition($this->channelServiceId, $channel);

        $container->setDefinition($this->setupCommandServiceId, new Definition("Skrz\\Bundle\\BunnyBundle\\Command\\SetupCommand", [
            new Reference($this->managerServiceId),
        ]));

        $container->setDefinition($this->consumerCommandServiceId, new Definition("Skrz\\Bundle\\BunnyBundle\\Command\\ConsumerCommand", [
            new Reference("service_container"),
            new Reference($this->managerServiceId),
            $consumers,
        ]));

        $container->setDefinition($this->producerCommandServiceId, new Definition("Skrz\\Bundle\\BunnyBundle\\Command\\ProducerCommand", [
            new Reference("service_container"),
            new Reference($this->managerServiceId),
            $producers,
        ]));
    }

}
