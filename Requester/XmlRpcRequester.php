<?php

namespace Mpp\UniversignBundle\Requester;

use Laminas\XmlRpc\Client;
use Laminas\XmlRpc\Client\Exception\FaultException;
use Mpp\UniversignBundle\Model\InitiatorInfo;
use Mpp\UniversignBundle\Model\RedirectionConfig;
use Mpp\UniversignBundle\Model\SignerInfo;
use Mpp\UniversignBundle\Model\TransactionInfo;
use Mpp\UniversignBundle\Model\TransactionRequest;
use Mpp\UniversignBundle\Model\TransactionResponse;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class XmlRpcRequester implements RequesterInterface
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var Router
     */
    protected $router;

    /**
     * @var array
     */
    protected $entrypoint;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var Client
     */
    protected $xmlRpcClient;

    public function __construct(LoggerInterface $logger, Router $router, array $entrypoint, array $options)
    {
        $this->logger = $logger;
        $this->router = $router;
        $this->entrypoint = $entrypoint;
        $this->options = $options;
        $this->xmlRpcClient = new Client($this->getURL());
    }

    /**
     * @return string;
     */
    public function getUrl()
    {
        return $this->entrypoint['url'];
    }

    /**
     * @param mixed $data
     * @param bool  $skipNullValue
     *
     * @return mixed
     */
    public static function flatten($data, bool $skipNullValue = true)
    {
        $flattenedData = [];

        if (is_object($data) &&
            !($data instanceof \Laminas\XmlRpc\Value\DateTime) &&
            !($data instanceof \Laminas\XmlRpc\Value\Base64)
        ) {
            return self::dismount($data, $skipNullValue);
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $flattenedValue = self::flatten($value, $skipNullValue);
                if (true === $skipNullValue && null === $flattenedValue) {
                    continue;
                }
                $flattenedData[$key] = $flattenedValue;
            }

            return $flattenedData;
        }

        return $data;
    }

    /**
     * @param mixed $object
     * @param bool  $skipNullValue
     *
     * @return array
     */
    public static function dismount($object, bool $skipNullValue = true): array
    {
        $rc = new \ReflectionClass($object);
        $data = [];
        foreach ($rc->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($object);
            $property->setAccessible(false);
            if (true === $skipNullValue && (null === $value || (is_array($value) && empty($value)))) {
                continue;
            }
            $data[$property->getName()] = self::flatten($value, $skipNullValue);
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function initiateTransactionRequest(array $options = []): TransactionRequest
    {
        $defaultOptions = [];

        if (null !== $this->options['registration_callback_route_name']) {
            $defaultOptions['registrationCallbackURL'] = $this->router->generate(
                $this->options['registration_callback_route_name'],
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        }

        if (null !== $this->options['success_redirection_route_name']) {
            $defaultOptions['successRedirection'] = RedirectionConfig::createFromArray([
                'URL' => $this->router->generate(
                    $this->options['success_redirection_route_name'],
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL
                ),
                'displayName' => 'Success',
            ]);
        }

        if (null !== $this->options['cancel_redirection_route_name']) {
            $defaultOptions['cancelRedirection'] = RedirectionConfig::createFromArray([
                'URL' => $this->router->generate(
                    $this->options['cancel_redirection_route_name'],
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL
                ),
                'displayName' => 'Success',
            ]);
        }

        if (null !== $this->options['fail_redirection_route_name']) {
            $defaultOptions['failRedirection'] = RedirectionConfig::createFromArray([
                'URL' => $this->router->generate(
                    $this->options['fail_redirection_route_name'],
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL
                ),
                'displayName' => 'Success',
            ]);
        }

        $transaction = TransactionRequest::createFromArray(array_merge($defaultOptions, $options));

        // TODO: Add redirections on signers ?

        return $transaction;
    }

    /**
     * {@inheritdoc}
     */
    public function requestTransaction(TransactionRequest $transactionRequest): TransactionResponse
    {
        $transactionResponse = new TransactionResponse();

        $flattenedTransactionRequest = self::flatten($transactionRequest);
        $flattenedTransactionRequest['documents'] = array_values($flattenedTransactionRequest['documents']);

        try {
            $response = $this->xmlRpcClient->call('requester.requestTransaction', [$flattenedTransactionRequest]);
            $this->logger->info('[Universign - requester.requestTransaction] SUCCESS');
            $transactionResponse
                ->setId($response['id'])
                ->setUrl($response['url'])
                ->setState(TransactionResponse::STATE_SUCCESS)
            ;
        } catch (FaultException $fe) {
            $transactionResponse
                ->setState(TransactionResponse::STATE_ERROR)
                ->setErrorCode($fe->getCode())
                ->setErrorMessage($fe->getMessage())
            ;

            $this->logger->error(sprintf(
                '[Universign - requester.requestTransaction] ERROR (%s): %s',
                $fe->getCode(),
                $fe->getMessage()
            ));
        }

        return $transactionResponse;
    }

    /**
     * {@inheritdoc}
     */
    public function getDocuments(string $documentId): array
    {
        $documents = [];

        try {
            $documents = $this->xmlRpcClient->call('requester.getDocuments', $documentId);
            $this->logger->info('[Universign - requester.getDocuments] SUCCESS');
        } catch (FaultException $fe) {
            $this->logger->error(sprintf(
                '[Universign - requester.getDocuments] ERROR (%s): %s',
                $fe->getCode(),
                $fe->getMessage()
            ));
        }

        return $documents;
    }

    /**
     * {@inheritdoc}
     */
    public function getDocumentsByCustomId(string $customId): array
    {
        $documents = [];

        try {
            $documents = $this->xmlRpcClient->call('requester.getDocumentsByCustomId', $customId);
            $this->logger->info('[Universign - requester.getDocumentsByCustomId] SUCCESS');
        } catch (FaultException $fe) {
            $this->logger->error(sprintf(
                '[Universign - requester.getDocumentsByCustomId] ERROR (%s): %s',
                $fe->getCode(),
                $fe->getMessage()
            ));
        }

        return $documents;
    }

    /**
     * {@inheritdoc}
     */
    public function getTransactionInfo(string $transactionId): TransactionInfo
    {
        $transactionInfo = new TransactionInfo();

        try {
            $response = $this->xmlRpcClient->call('requester.getTransactionInfo', $transactionId);
            $this->logger->info('[Universign - requester.getTransactionInfo] SUCCESS');
            $transactionInfo
                ->setState(TransactionInfo::STATE_SUCCESS)
                ->setStatus($response['status'] ?? null)
                ->setCurrentSigner($response['currentSigner'] ?? null)
                ->setCreationDate(\DateTime::createFromFormat('Ymd\TH:i:s', $response['creationDate']) ?? null)
                ->setDescription($response['description'] ?? null)
                ->setInitiatorInfo(InitiatorInfo::createFromArray($response['initiatorInfo']) ?? null)
                ->setEachField($response['eachField'] ?? null)
                ->setCustomerId($response['customerId'] ?? null)
                ->setTransactionId($response['transactionId'] ?? null)
                ->setRedirectPolicy($response['redirectPolicy'] ?? null)
                ->setRedirectWait($response['redirectWait'] ?? null)
            ;
            foreach ($response['signerInfos'] as $signerInfo) {
                $transactionInfo->addSignerInfo(SignerInfo::createFromArray($signerInfo));
            }
        } catch (FaultException $fe) {
            $this->logger->error(sprintf(
                '[Universign - requester.getTransactionInfo] ERROR (%s): %s',
                $fe->getCode(),
                $fe->getMessage()
            ));
            $transactionInfo
                ->setState(TransactionInfo::STATE_ERROR)
                ->setErrorCode($fe->getCode())
                ->setErrorMessage($fe->getMessage())
            ;
        }

        return $transactionInfo;
    }

    /**
     * {@inheritdoc}
     */
    public function getTransactionInfoByCustomId(string $customId): TransactionInfo
    {
        $transactionInfo = new TransactionInfo();

        try {
            $response = $this->xmlRpcClient->call('requester.getTransactionInfoByCustomId', $customId);
            $this->logger->info('[Universign - requester.getTransactionInfoByCustomId] SUCCESS');
            $transactionInfo
                ->setState(TransactionInfo::STATE_SUCCESS)
                ->setStatus($response['status'] ?? null)
                ->setCurrentSigner($response['currentSigner'] ?? null)
                ->setCreationDate(\DateTime::createFromFormat('Ymd\TH:i:s', $response['creationDate']) ?? null)
                ->setDescription($response['description'] ?? null)
                ->setInitiatorInfo(InitiatorInfo::createFromArray($response['initiatorInfo']) ?? null)
                ->setEachField($response['eachField'] ?? null)
                ->setCustomerId($response['customerId'] ?? null)
                ->setTransactionId($response['transactionId'] ?? null)
                ->setRedirectPolicy($response['redirectPolicy'] ?? null)
                ->setRedirectWait($response['redirectWait'] ?? null)
            ;
        } catch (FaultException $fe) {
            $this->logger->error(sprintf(
                '[Universign - requester.getTransactionInfoByCustomId] ERROR (%s): %s',
                $fe->getCode(),
                $fe->getMessage()
            ));
            $transactionInfo
                ->setState(TransactionInfo::STATE_ERROR)
                ->setErrorCode($fe->getCode())
                ->setErrorMessage($fe->getMessage())
            ;
        }

        return $transactionInfo;
    }

    /**
     * {@inheritdoc}
     */
    public function relaunchTransaction(string $transactionId): TransactionInfo
    {
        $transactionInfo = new TransactionInfo();

        try {
            $response = $this->xmlRpcClient->call('requester.relaunchTransaction', $transactionId);
            $this->logger->info('[Universign - requester.relaunchTransaction] SUCCESS');
            $transactionInfo
                ->setState(TransactionInfo::STATE_SUCCESS)
            ;
        } catch (FaultException $fe) {
            $this->logger->error(sprintf(
                '[Universign - requester.relaunchTransaction] ERROR (%s): %s',
                $fe->getCode(),
                $fe->getMessage()
            ));
            $transactionInfo
                ->setState(TransactionInfo::STATE_ERROR)
                ->setErrorCode($fe->getCode())
                ->setErrorMessage($fe->getMessage())
            ;
        }

        return $transactionInfo;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTransaction(string $transactionId): TransactionInfo
    {
        $transactionInfo = new TransactionInfo();

        try {
            $response = $this->xmlRpcClient->call('requester.cancelTransaction', $transactionId);
            $this->logger->info('[Universign - requester.cancelTransaction] SUCCESS');
            $transactionInfo
                ->setState(TransactionInfo::STATE_SUCCESS)
            ;
        } catch (FaultException $fe) {
            $this->logger->error(sprintf(
                '[Universign - requester.cancelTransaction] ERROR (%s): %s',
                $fe->getCode(),
                $fe->getMessage()
            ));
            $transactionInfo
                ->setState(TransactionInfo::STATE_ERROR)
                ->setErrorCode($fe->getCode())
                ->setErrorMessage($fe->getMessage())
            ;
        }

        return $transactionInfo;
    }
}
