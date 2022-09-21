<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Broadway\Domain\DateTime;
use Broadway\Domain\DomainMessage;
use Broadway\Domain\Metadata;
use Broadway\Serializer\Serializable;
use Broadway\Serializer\Serializer;

final class DomainMessageNormalizer
{
    public function __construct(
        private readonly Serializer $metadataSerializer,
        private readonly Serializer $payloadSerializer,
        private readonly JsonEncoder $jsonEncoder,
        private readonly JsonDecoder $jsonDecoder
    ) {
    }

    /**
     * @return array{id: string, playhead: int, metadataClass: string, metadataPayload: string, payloadClass: string, payloadPayload: string, recordedOn: string, type: string}
     */
    public function normalize(DomainMessage $domainMessage): array
    {
        $serializedMetadata = $this->metadataSerializer->serialize($domainMessage->getMetadata());
        $serializedPayload  = $this->payloadSerializer->serialize($domainMessage->getPayload());

        return [
            'id'              => $domainMessage->getId(),
            'playhead'        => $domainMessage->getPlayhead(),
            'metadataClass'   => $serializedMetadata['class'],
            'metadataPayload' => $this->jsonEncoder->encode($serializedMetadata['payload']),
            'payloadClass'    => $serializedPayload['class'],
            'payloadPayload'  => $this->jsonEncoder->encode($serializedPayload['payload']),
            'recordedOn'      => $domainMessage->getRecordedOn()->toString(),
            'type'            => $domainMessage->getType(),
        ];
    }

    /**
     * @param array<string, AttributeValue> $event
     */
    public function denormalize(array $event): DomainMessage
    {
        $serializedMetadata = $event['Metadata']->getM();
        $serializedPayload  = $event['Payload']->getM();

        $metadataPayloadString = $serializedMetadata['Payload']->getS();

        if (!is_string($metadataPayloadString)) {
            throw new UnexpectedDomainMessageContent('Metadata Payload', 'string', $metadataPayloadString);
        }

        $payloadPayloadString = $serializedPayload['Payload']->getS();

        if (!is_string($payloadPayloadString)) {
            throw new UnexpectedDomainMessageContent('Payload Payload', 'string', $payloadPayloadString);
        }

        $recordedOnString = $event['RecordedOn']->getS();

        if (!is_string($recordedOnString)) {
            throw new UnexpectedDomainMessageContent('RecordedOn', 'string', $recordedOnString);
        }

        $metadata = $this->metadataSerializer->deserialize([
            'class'   => $serializedMetadata['Class']->getS(),
            'payload' => $this->jsonDecoder->decode($metadataPayloadString),
        ]);

        if (!$metadata instanceof Metadata) {
            throw new UnexpectedDomainMessageContent('Metadata', Metadata::class, $metadata);
        }

        $payload = $this->payloadSerializer->deserialize([
            'class'   => $serializedPayload['Class']->getS(),
            'payload' => $this->jsonDecoder->decode($payloadPayloadString),
        ]);

        if (!$payload instanceof Serializable) {
            throw new UnexpectedDomainMessageContent('Payload', Serializable::class, $payload);
        }

        return new DomainMessage(
            $event['Id']->getS(),
            (int) $event['Playhead']->getN(),
            $metadata,
            $payload,
            DateTime::fromString($recordedOnString)
        );
    }
}
