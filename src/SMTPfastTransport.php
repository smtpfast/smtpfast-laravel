<?php

declare(strict_types=1);

namespace SMTPfast\Laravel;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SMTPfast\Client;
use SMTPfast\Exception\SMTPfastException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends Symfony and Laravel mail through the SMTPfast API (POST /v1/emails).
 * The SMTPfast email id becomes the message id of the sent message.
 */
final class SMTPfastTransport extends AbstractTransport
{
    /** Headers that are fields of the send request, or that the API sets itself. */
    private const NOT_CUSTOM = [
        'from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'return-path',
        'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding',
    ];

    /** SMTPfast's own instructions, sent as request fields instead of headers. */
    private const CATEGORY_HEADER = 'x-smtpfast-category';
    private const TRACKING_HEADERS = ['x-smtpfast-track-clicks' => 'track_clicks', 'x-smtpfast-track-opens' => 'track_opens'];

    /** How Symfony finds the cid: references in the HTML when it builds a MIME message. */
    private const CID_PATTERNS = [
        '/<img\s+[^>]*src\s*=\s*(?:([\'"])cid:(.+?)\1|cid:([^>\s]+))/i',
        '/<\w+\s+[^>]*background\s*=\s*(?:([\'"])cid:(.+?)\1|cid:([^>\s]+))/i',
    ];

    /**
     * @param string|null $category transactional or marketing, for mail without an X-SMTPfast-Category header;
     *                              null leaves it to the API, which treats it as marketing
     */
    public function __construct(
        private readonly Client $client,
        private readonly ?string $category = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    public function __toString(): string
    {
        return 'smtpfast';
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        try {
            $result = $this->client->emails->send($this->payload($email, $message->getEnvelope()));
        } catch (SMTPfastException $e) {
            throw new TransportException('Request to the SMTPfast API failed: ' . $e->getMessage(), 0, $e);
        }

        $id = is_array($result) && isset($result['id']) && is_string($result['id']) ? $result['id'] : '';
        if ($id === '') {
            throw new TransportException('The SMTPfast API answered without an email id.');
        }
        $message->setMessageId($id);
    }

    /**
     * The POST /v1/emails request for a message.
     *
     * @return array<string, mixed>
     */
    public function payload(Email $email, Envelope $envelope): array
    {
        [$to, $cc, $bcc] = self::recipients($email, $envelope);
        $payload = [
            'from' => ($email->getFrom()[0] ?? $envelope->getSender())->toString(),
            'to' => self::addresses($to),
        ];
        if ($cc !== []) {
            $payload['cc'] = self::addresses($cc);
        }
        if ($bcc !== []) {
            $payload['bcc'] = self::addresses($bcc);
        }
        if ($email->getReplyTo() !== []) {
            $payload['reply_to'] = self::addresses($email->getReplyTo());
        }
        if ($email->getSubject() !== null) {
            $payload['subject'] = $email->getSubject();
        }

        [$attachments, $html] = $this->attachments($email, self::body($email->getHtmlBody(), $email->getHtmlCharset()));
        $text = self::body($email->getTextBody(), $email->getTextCharset());
        if ($html !== null) {
            $payload['html'] = $html;
        }
        if ($text !== null) {
            $payload['text'] = $text;
        }
        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        $category = $this->category;
        $headers = [];
        $tags = [];
        foreach ($email->getHeaders()->all() as $header) {
            if ($header instanceof MetadataHeader) {
                $tags[] = ['name' => $header->getKey(), 'value' => $header->getValue()];
                continue;
            }
            $name = strtolower($header->getName());
            if (in_array($name, self::NOT_CUSTOM, true)) {
                continue;
            }
            $value = $header->getBodyAsString();
            if ($name === self::CATEGORY_HEADER) {
                $category = strtolower(trim($value));
                continue;
            }
            $flag = strtolower(trim($value));
            if (isset(self::TRACKING_HEADERS[$name]) && ($flag === 'true' || $flag === 'false')) {
                $payload[self::TRACKING_HEADERS[$name]] = $flag === 'true';
                continue;
            }
            $headers[$header->getName()] = $value;
        }
        if ($headers !== []) {
            $payload['headers'] = $headers;
        }
        if ($tags !== []) {
            $payload['tags'] = $tags;
        }
        if ($category !== null && $category !== '') {
            $payload['category'] = $category;
        }

        return $payload;
    }

    /**
     * To, Cc and Bcc come from the message headers, so a Bcc address can
     * never end up in the visible To or Cc. An envelope recipient that is in
     * none of them (compared by address, ignoring case) is added to Bcc.
     *
     * @return array{list<Address>, list<Address>, list<Address>}
     */
    private static function recipients(Email $email, Envelope $envelope): array
    {
        [$to, $cc, $bcc] = [array_values($email->getTo()), array_values($email->getCc()), array_values($email->getBcc())];
        $known = [];
        foreach ([...$to, ...$cc, ...$bcc] as $address) {
            $known[strtolower($address->getAddress())] = true;
        }
        foreach ($envelope->getRecipients() as $address) {
            $key = strtolower($address->getAddress());
            if (!isset($known[$key])) {
                $known[$key] = true;
                $bcc[] = $address;
            }
        }

        return [$to, $cc, $bcc];
    }

    /**
     * Attachments for the request, and the HTML with each cid: reference
     * pointing at its part's Content-ID. A part the HTML refers to by name
     * becomes inline, as it does when Symfony builds the MIME message.
     *
     * @return array{list<array<string, string>>, ?string}
     */
    private function attachments(Email $email, ?string $html): array
    {
        $names = [];
        if ($html !== null) {
            foreach (self::CID_PATTERNS as $pattern) {
                preg_match_all($pattern, $html, $matches);
                $names = array_merge($names, $matches[2], $matches[3]);
            }
            $names = array_values(array_unique(array_filter($names, fn (string $name): bool => $name !== '')));
        }

        $items = [];
        $related = [];
        $replacements = [];
        foreach ($email->getAttachments() as $part) {
            $inline = $part->getDisposition() === 'inline';
            foreach ($names as $name) {
                if ($name !== $part->getName() && (!$part->hasContentId() || $name !== $part->getContentId())) {
                    continue;
                }
                if (!isset($related[$name])) {
                    $related[$name] = true;
                    $replacements['cid:' . $name] = 'cid:' . $part->getContentId();
                    $inline = true;
                }
                break;
            }
            $item = [
                'filename' => $part->getFilename() ?? $part->getName() ?? 'attachment',
                'content' => base64_encode($part->getBody()),
                'content_type' => $part->getContentType(),
            ];
            if ($inline) {
                $item['content_id'] = $part->getContentId();
            }
            $items[] = $item;
        }
        if ($html !== null && $replacements !== []) {
            // One pass, longest name first, as Symfony does.
            $html = strtr($html, $replacements);
        }

        return [$items, $html];
    }

    /**
     * @param Address[] $addresses
     *
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $address): string => $address->toString(), $addresses));
    }

    /**
     * @param resource|string|null $body
     */
    private static function body(mixed $body, ?string $charset): ?string
    {
        if (is_resource($body)) {
            if (stream_get_meta_data($body)['seekable']) {
                rewind($body);
            }
            $body = stream_get_contents($body);
        }
        if (!is_string($body)) {
            return null;
        }
        if ($charset !== null && strcasecmp($charset, 'utf-8') !== 0) {
            $converted = mb_convert_encoding($body, 'UTF-8', $charset);
            $body = is_string($converted) ? $converted : $body;
        }

        return $body;
    }
}
