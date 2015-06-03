<?php
namespace Skrz\Bundle\BunnyBundle\Command;

use Skrz\Bundle\BunnyBundle\AbstractProducer;
use Skrz\Bundle\BunnyBundle\Annotation\Producer;
use Skrz\Bundle\BunnyBundle\BunnyManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ProducerCommand extends Command
{

	/** @var ContainerInterface */
	private $container;

	/** @var BunnyManager */
	private $manager;

	/** @var Producer[] */
	private $producers;

	public function __construct(ContainerInterface $container, BunnyManager $manager, array $producers)
	{
		parent::__construct("bunny:producer");
		$this->container = $container;
		$this->manager = $manager;
		$this->producers = [];
		foreach ($producers as $producerName => $producer) {
			$this->producers[$producerName] = Producer::fromArray($producer);
		}
	}

	protected function configure()
	{
		$this
			->setDescription("Send message through producer.")
			->addArgument("producer-name", InputArgument::REQUIRED, "Name of the producer.")
			->addArgument("message", InputArgument::REQUIRED, "Message JSON string.")
			->addArgument("routing-key", InputArgument::OPTIONAL, "Message's routing key.", null)
			->addOption("count", "c", InputOption::VALUE_REQUIRED, "Message will be published X times.", 1);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$producerName = strtolower($input->getArgument("producer-name"));
		$message = $input->getArgument("message");
		$routingKey = $input->getArgument("routing-key");

		if (!isset($this->producers[$producerName])) {
			throw new \InvalidArgumentException("Producer '{$producerName}' does not exist.");
		}

		/** @var AbstractProducer $producer */
		$producer = $this->container->get($this->producers[$producerName]->name);

		if (!($producer instanceof AbstractProducer)) {
			throw new \LogicException("Producer '{$producerName}' is not instance of AbstractProducer.");
		}

		$this->manager->setUp();

		for ($i = 0, $count = $input->getOption("count"); $i < $count; ++$i) {
			$producer->publish($message, $routingKey);
		}
	}

}