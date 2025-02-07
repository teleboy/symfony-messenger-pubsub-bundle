<?php
namespace CedricZiel\Symfony\Bundle\GoogleCloudPubSubMessenger\Controller;

use CedricZiel\Symfony\Messenger\Bridge\GcpPubSub\PushWorker;
use CedricZiel\Symfony\Messenger\Bridge\GcpPubSub\Transport\PubSubPushStamp;
use CedricZiel\Symfony\Messenger\Bridge\GcpPubSub\Transport\PubSubReceivedStamp;
use CedricZiel\Symfony\Messenger\Bridge\GcpPubSub\Transport\PubSubTransport;
use Google\Cloud\PubSub\PubSubClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;

use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @see ConsumeMessagesCommand
 */
class PushController
{
    private MessageBusInterface $bus;

    private ?EventDispatcherInterface $eventDispatcher;

    private ?LoggerInterface $logger;

    private ServiceLocator $receiverLocator;

    public function __construct(MessageBusInterface $bus, ServiceLocator $receiverLocator, EventDispatcherInterface $eventDispatcher = null, LoggerInterface $logger = null)
    {
        $this->bus             = $bus;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger          = $logger;
        $this->receiverLocator = $receiverLocator;
    }

    public function __invoke(Request $request, $transport): Response
    {
        $serviceName = \sprintf('messenger.transport.%s', $transport);

        if (!$this->receiverLocator->has($serviceName)) {
            throw new NotFoundHttpException(\sprintf('No such transport "%s"', $transport));
        }

        /** @var TransportInterface $foundTransport */
        $foundTransport = $this->receiverLocator->get($serviceName);

        if (!($foundTransport instanceof PubSubTransport)) {
            throw new BadRequestHttpException(\sprintf('"%s" is not a Pub/Sub transport', $transport));
        }

        $pubSub = new PubSubClient($foundTransport->getClientConfig());

        $content = $request->getContent();

        if (empty($content)) {
            throw new BadRequestHttpException('No message present in request.');
        }

        $rawMessage = \json_decode($content, true);

        if ($rawMessage === null) {
            throw new BadRequestHttpException('Unable to deserialize message.');
        }

        $message = $pubSub->consume($rawMessage);

        $body       = $message->data();
        $attributes = $message->attributes();

        try {
            $envelope = $foundTransport->getSerializer()->decode([
                'body'    => $body,
                'headers' => $attributes,
            ]);
        } catch (MessageDecodingFailedException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        $envelope = $envelope
            ->with(new PubSubReceivedStamp($message, $message->subscription()))
            ->with(new PubSubPushStamp());

        $worker = new PushWorker($this->bus, $this->eventDispatcher, $this->logger);
        $worker->work($envelope, $transport);

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
