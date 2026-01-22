# API Reference - php8-smpp

Complete API documentation for the php8-smpp SMPP v3.4 client library.

## Table of Contents

- [Client](#client)
- [ClientBuilder](#clientbuilder)
- [Address](#address)
- [Sms](#sms)
- [DeliveryReceipt](#deliveryreceipt)
- [Tag](#tag)
- [SmppConfig](#smppconfig)
- [SocketTransportConfig](#sockettransportconfig)
- [Constants](#constants)
- [Exceptions](#exceptions)

---

## Client

The main SMPP client class for sending and receiving SMS messages.

**Namespace:** `Smpp\Client`

### Constructor

```php
public function __construct(
    TransportInterface $transport,
    string $systemId,
    string $password
)
```

**Parameters:**
- `$transport` - Transport implementation (SocketTransport or SCTPTransport)
- `$systemId` - SMSC system ID for authentication
- `$password` - SMSC password for authentication

**Example:**
```php
use Smpp\Client;
use Smpp\Transport\SocketTransport;
use Smpp\Configs\SocketTransportConfig;
use Smpp\Utils\Network\DSNParser;

$transport = new SocketTransport(
    DSNParser::parseDSNEntries('smpp.example.com:2775'),
    new SocketTransportConfig()
);

$client = new Client($transport, 'username', 'password');
```

### Methods

#### bindTransmitter()

Binds the client as a transmitter (send-only mode).

```php
public function bindTransmitter(): void
```

**Throws:**
- `SmppException` - On binding failure or invalid credentials
- `SocketTransportException` - On connection failure

**Example:**
```php
$client->bindTransmitter();
```

---

#### bindReceiver()

Binds the client as a receiver (receive-only mode).

```php
public function bindReceiver(): void
```

**Throws:**
- `SmppException` - On binding failure
- `SocketTransportException` - On connection failure

**Example:**
```php
$client->bindReceiver();
```

---

#### bindTransceiver()

Binds the client as a transceiver (bidirectional mode).

```php
public function bindTransceiver(): void
```

**Throws:**
- `SmppException` - On binding failure
- `SocketTransportException` - On connection failure

**Example:**
```php
$client->bindTransceiver();
```

---

#### sendSMS()

Sends an SMS message. Automatically handles concatenated SMS for long messages.

```php
public function sendSMS(
    Address $from,
    Address $to,
    string $message,
    ?array $tags = null,
    int $dataCoding = Smpp::DATA_CODING_DEFAULT,
    int $priority = 0x00,
    $scheduleDeliveryTime = null,
    $validityPeriod = null
): bool|string
```

**Parameters:**
- `$from` - Source address (sender)
- `$to` - Destination address (recipient)
- `$message` - Message text (encoding depends on $dataCoding)
- `$tags` - Optional TLV tags (Tag[] array)
- `$dataCoding` - Data coding scheme (default: GSM 03.38)
  - `Smpp::DATA_CODING_DEFAULT` - GSM 03.38 (7-bit)
  - `Smpp::DATA_CODING_UCS2` - Unicode (UTF-16BE)
  - `Smpp::DATA_CODING_BINARY` - Binary data
- `$priority` - Priority level (0-3, default: 0)
- `$scheduleDeliveryTime` - Scheduled delivery time (SMPP time format)
- `$validityPeriod` - Message validity period (SMPP time format)

**Returns:**
- `string` - Message ID from SMSC on success
- `false` - On failure (e.g., unsupported encoding for long messages)

**Throws:**
- `SmppException` - On protocol errors
- `SocketTransportException` - On network errors

**Example:**
```php
use Smpp\Pdu\Address;
use Smpp\Smpp;

$from = new Address('1234', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
$to = new Address('5678901234', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);

// Send simple SMS
$messageId = $client->sendSMS($from, $to, 'Hello World');

// Send Unicode SMS with emoji
$messageId = $client->sendSMS(
    $from,
    $to,
    'Hello 你好 🎉',
    null,
    Smpp::DATA_CODING_UCS2
);

// Send with priority
$messageId = $client->sendSMS($from, $to, 'Urgent!', null, Smpp::DATA_CODING_DEFAULT, 0x03);
```

**Long Message Handling:**
- GSM 03.38: Messages >160 chars are split into 152/153 char segments
- UCS-2: Messages >70 chars are split into 66 char segments
- Splitting method configured via `SmppConfig::setCsmsMethod()`

---

#### readSMS()

Reads one SMS from SMSC. Blocks until message arrives or timeout.

```php
public function readSMS(): bool|DeliveryReceipt|Sms
```

**Returns:**
- `Sms` - Received SMS message
- `DeliveryReceipt` - Delivery receipt
- `false` - On timeout or no message

**Throws:**
- `SmppException` - On protocol errors

**Example:**
```php
$client->bindReceiver();

while (true) {
    $sms = $client->readSMS();

    if ($sms === false) {
        // Timeout, continue
        continue;
    }

    if ($sms instanceof \Smpp\Pdu\DeliveryReceipt) {
        echo "Delivery receipt: " . $sms->getStatus() . "\n";
    } else {
        echo "Message from: " . $sms->getSource()->getValue() . "\n";
        echo "Text: " . $sms->getMessage() . "\n";
    }
}
```

---

#### queryStatus()

Queries the current status of a previously sent SMS.

```php
public function queryStatus(string $messageID, Address $source): ?array
```

**Parameters:**
- `$messageID` - SMSC-assigned message ID from sendSMS()
- `$source` - Source address used when sending the message

**Returns:**
- `array` - Status information with keys:
  - `message_id` (string) - Message identifier
  - `final_date` (DateTime|null) - Final status date
  - `message_state` (int) - Message state (Smpp::STATE_* constants)
  - `error_code` (int) - Network-specific error code
- `null` - On error or message not found

**Example:**
```php
$messageId = $client->sendSMS($from, $to, 'Test');

// Query status later
$status = $client->queryStatus($messageId, $from);

if ($status) {
    switch ($status['message_state']) {
        case Smpp::STATE_DELIVERED:
            echo "Message delivered\n";
            break;
        case Smpp::STATE_EXPIRED:
            echo "Message expired\n";
            break;
        case Smpp::STATE_REJECTED:
            echo "Message rejected\n";
            break;
    }
}
```

**Message States:**
- `Smpp::STATE_ENROUTE` - Message is in transit
- `Smpp::STATE_DELIVERED` - Message delivered to destination
- `Smpp::STATE_EXPIRED` - Message validity period expired
- `Smpp::STATE_DELETED` - Message deleted
- `Smpp::STATE_UNDELIVERABLE` - Message undeliverable
- `Smpp::STATE_ACCEPTED` - Message accepted
- `Smpp::STATE_UNKNOWN` - Unknown state
- `Smpp::STATE_REJECTED` - Message rejected
- `Smpp::STATE_SKIPPED` - Message skipped

---

#### enquireLink()

Sends ENQUIRE_LINK command to keep connection alive.

```php
public function enquireLink(): Pdu
```

**Returns:**
- `Pdu` - ENQUIRE_LINK_RESP PDU

**Example:**
```php
// Keep-alive loop
while (true) {
    $client->enquireLink();
    sleep(30); // Send every 30 seconds
}
```

---

#### respondEnquireLink()

Responds to any pending ENQUIRE_LINK from SMSC.

```php
public function respondEnquireLink(): void
```

**Example:**
```php
// In read loop, respond to SMSC keep-alive
$client->respondEnquireLink();
$sms = $client->readSMS();
```

---

#### close()

Closes the SMPP session (sends UNBIND and closes transport).

```php
public function close(): void
```

**Example:**
```php
$client->close();
```

---

#### parseSmppTime()

Parses SMPP time format (SMPP v3.4 section 7.1).

```php
public function parseSmppTime(string $input): ?DateTime|DateInterval
```

**Parameters:**
- `$input` - SMPP formatted time string (e.g., "260122120000000+")

**Returns:**
- `DateTime` - Absolute time
- `DateInterval` - Relative time
- `null` - On parse error

**Example:**
```php
$date = $client->parseSmppTime('260122120000000+');
// Returns: DateTime('2026-01-22 12:00:00+00:00')
```

---

## ClientBuilder

Fluent builder for creating SMPP clients.

**Namespace:** `Smpp\ClientBuilder`

### Static Factory Methods

#### createForSockets()

Creates a builder with TCP socket transport.

```php
public static function createForSockets(
    array $dsnEntries,
    ?SocketTransportConfig $config = null
): static
```

**Parameters:**
- `$dsnEntries` - Array of DSN strings (e.g., ['host:port'])
- `$config` - Optional socket transport configuration

**Example:**
```php
use Smpp\ClientBuilder;

$builder = ClientBuilder::createForSockets(['smpp.example.com:2775']);
```

---

#### createForSCTP()

Creates a builder with SCTP transport.

```php
public static function createForSCTP(
    array $dsnEntries,
    ?SocketTransportConfig $config = null
): static
```

**Example:**
```php
$builder = ClientBuilder::createForSCTP(['smpp.example.com:2775']);
```

---

### Builder Methods

#### setLogger()

Sets a PSR-3 compatible logger.

```php
public function setLogger(LoggerInterface $logger): self
```

**Example:**
```php
$builder->setLogger(new \Monolog\Logger('smpp'));
```

---

#### setConfig()

Sets SMPP configuration.

```php
public function setConfig(SmppConfig $config): self
```

**Example:**
```php
use Smpp\Configs\SmppConfig;
use Smpp\Smpp;

$config = new SmppConfig();
$config->setCsmsMethod(Smpp::CSMS_16BIT_TAGS);

$builder->setConfig($config);
```

---

#### setSystemId()

Sets SMSC system ID (username).

```php
public function setSystemId(string $systemId): self
```

---

#### setPassword()

Sets SMSC password.

```php
public function setPassword(string $password): self
```

---

#### build()

Builds and returns the configured Client instance.

```php
public function build(): Client
```

**Example:**
```php
$client = ClientBuilder::createForSockets(['smpp.example.com:2775'])
    ->setSystemId('username')
    ->setPassword('password')
    ->setLogger($logger)
    ->build();

$client->bindTransmitter();
```

---

## Address

Represents an SMPP address with Type of Number (TON) and Numbering Plan Indicator (NPI).

**Namespace:** `Smpp\Pdu\Address`

### Constructor

```php
public function __construct(
    string $value,
    int $numberType = Smpp::TON_UNKNOWN,
    int $numberingPlanIndicator = Smpp::NPI_UNKNOWN
)
```

**Parameters:**
- `$value` - Address value (phone number or alphanumeric)
- `$numberType` - Type of Number (TON)
- `$numberingPlanIndicator` - Numbering Plan Indicator (NPI)

**Throws:**
- `SmppInvalidArgumentException` - If address exceeds allowed length

**Example:**
```php
use Smpp\Pdu\Address;
use Smpp\Smpp;

// International phone number
$addr = new Address('1234567890', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);

// Alphanumeric sender (max 11 chars)
$sender = new Address('MyCompany', Smpp::TON_ALPHANUMERIC, Smpp::NPI_UNKNOWN);

// Short code
$shortcode = new Address('12345', Smpp::TON_UNKNOWN, Smpp::NPI_UNKNOWN);
```

### Methods

#### getValue()

```php
public function getValue(): string
```

Returns the address value.

---

#### getNumberType()

```php
public function getNumberType(): int
```

Returns the Type of Number (TON).

---

#### getNumberingPlanIndicator()

```php
public function getNumberingPlanIndicator(): int
```

Returns the Numbering Plan Indicator (NPI).

---

## Sms

Represents a received SMS message.

**Namespace:** `Smpp\Pdu\Sms`

### Methods

#### getMessage()

```php
public function getMessage(): string
```

Returns the message text (encoding depends on data coding).

---

#### getSource()

```php
public function getSource(): Address
```

Returns the source (sender) address.

---

#### getDestination()

```php
public function getDestination(): Address
```

Returns the destination (recipient) address.

---

#### getDataCoding()

```php
public function getDataCoding(): int
```

Returns the data coding scheme.

---

#### getTags()

```php
public function getTags(): array
```

Returns array of optional TLV tags.

---

## DeliveryReceipt

Extends Sms with delivery receipt parsing.

**Namespace:** `Smpp\Pdu\DeliveryReceipt`

### Additional Methods

#### getStatus()

```php
public function getStatus(): string
```

Returns delivery status (DELIVRD, EXPIRED, REJECTED, etc.).

---

#### getDlvrd()

```php
public function getDlvrd(): int
```

Returns number of messages delivered.

---

#### getSubmitDate()

```php
public function getSubmitDate(): ?DateTime
```

Returns submission timestamp.

---

#### getDoneDate()

```php
public function getDoneDate(): ?DateTime
```

Returns completion timestamp.

---

## Tag

Represents an optional TLV (Tag-Length-Value) parameter.

**Namespace:** `Smpp\Pdu\Tag`

### Constructor

```php
public function __construct(
    int $id,
    string|int $value,
    ?int $length = null,
    ?string $packFormat = null
)
```

**Example:**
```php
use Smpp\Pdu\Tag;

// Message payload tag
$payload = new Tag(Tag::MESSAGE_PAYLOAD, $message, strlen($message));

// SAR (concatenated SMS) tags
$refNum = new Tag(Tag::SAR_MSG_REF_NUM, 12345, 2, 'n');
$totalSegs = new Tag(Tag::SAR_TOTAL_SEGMENTS, 3, 1, 'c');
$segNum = new Tag(Tag::SAR_SEGMENT_SEQNUM, 1, 1, 'c');
```

### Common Tag IDs

- `Tag::MESSAGE_PAYLOAD` (0x0424) - Message payload
- `Tag::SAR_MSG_REF_NUM` (0x020C) - CSMS reference number
- `Tag::SAR_TOTAL_SEGMENTS` (0x020E) - Total segments
- `Tag::SAR_SEGMENT_SEQNUM` (0x020F) - Segment sequence number

---

## SmppConfig

Configuration for SMPP protocol parameters.

**Namespace:** `Smpp\Configs\SmppConfig`

### Methods

#### setCsmsMethod()

Sets concatenated SMS method.

```php
public function setCsmsMethod(int $method): self
```

**Values:**
- `Smpp::CSMS_16BIT_TAGS` - 16-bit SAR tags (default)
- `Smpp::CSMS_8BIT_UDH` - 8-bit UDH headers
- `Smpp::CSMS_PAYLOAD` - Message payload method

---

#### setSystemType()

```php
public function setSystemType(string $systemType): self
```

Sets the system type string (default: "").

---

#### setAddressRange()

```php
public function setAddressRange(string $addressRange): self
```

Sets the address range for receiver binding.

---

#### setSmsRegisteredDeliveryFlag()

```php
public function setSmsRegisteredDeliveryFlag(int $flag): self
```

Sets delivery receipt request flag (0x00 = no receipt, 0x01 = receipt requested).

---

## SocketTransportConfig

Configuration for socket transport layer.

**Namespace:** `Smpp\Configs\SocketTransportConfig`

### Methods

#### setReadTimeout()

```php
public function setReadTimeout(int $milliseconds): self
```

Sets socket read timeout in milliseconds.

---

#### setConnectTimeout()

```php
public function setConnectTimeout(int $milliseconds): self
```

Sets connection timeout in milliseconds.

---

#### setReadStrategy()

```php
public function setReadStrategy(ReadStrategyInterface $strategy): self
```

Sets the read strategy (blocking, non-blocking, hybrid).

**Example:**
```php
use Smpp\Transport\NonBlockingReadStrategy;

$config = new SocketTransportConfig();
$config->setReadStrategy(new NonBlockingReadStrategy());
```

---

## Constants

### Type of Number (TON)

```php
Smpp::TON_UNKNOWN = 0x00;
Smpp::TON_INTERNATIONAL = 0x01;
Smpp::TON_NATIONAL = 0x02;
Smpp::TON_NETWORK = 0x03;
Smpp::TON_SUBSCRIBER = 0x04;
Smpp::TON_ALPHANUMERIC = 0x05;
Smpp::TON_ABBREVIATED = 0x06;
```

### Numbering Plan Indicator (NPI)

```php
Smpp::NPI_UNKNOWN = 0x00;
Smpp::NPI_E164 = 0x01;          // ISDN/telephone
Smpp::NPI_DATA = 0x03;
Smpp::NPI_TELEX = 0x04;
Smpp::NPI_NATIONAL = 0x08;
Smpp::NPI_PRIVATE = 0x09;
Smpp::NPI_ERMES = 0x0A;
Smpp::NPI_INTERNET = 0x0E;
Smpp::NPI_WAP = 0x12;
```

### Data Coding

```php
Smpp::DATA_CODING_DEFAULT = 0x00;    // GSM 03.38 (7-bit)
Smpp::DATA_CODING_IA5 = 0x01;        // ASCII
Smpp::DATA_CODING_BINARY = 0x02;     // Binary/Octet
Smpp::DATA_CODING_ISO8859_1 = 0x03;  // Latin-1
Smpp::DATA_CODING_UCS2 = 0x08;       // UCS-2/UTF-16BE
```

### ESM Class

```php
Smpp::ESM_SUBMIT_DEFAULT = 0x00;
Smpp::ESM_SUBMIT_DATAGRAM = 0x01;
Smpp::ESM_DELIVER_SMSC_RECEIPT = 0x04;
```

---

## Exceptions

### SmppException

Base exception for SMPP protocol errors.

**Namespace:** `Smpp\Exceptions\SmppException`

```php
try {
    $client->sendSMS($from, $to, $message);
} catch (\Smpp\Exceptions\SmppException $e) {
    echo "SMPP Error: " . $e->getMessage();
    echo "Error Code: " . $e->getCode();
}
```

---

### SocketTransportException

Network/transport layer errors.

**Namespace:** `Smpp\Exceptions\SocketTransportException`

---

### SocketTimeoutException

Socket operation timeout.

**Namespace:** `Smpp\Exceptions\SocketTimeoutException`

Implements `RetryableExceptionInterface` - can be automatically retried.

---

### SmppInvalidArgumentException

Invalid parameter values.

**Namespace:** `Smpp\Exceptions\SmppInvalidArgumentException`

---

### PDUParseException

PDU parsing errors.

**Namespace:** `Smpp\Exceptions\PDUParseException`

---

## Error Handling Best Practices

```php
use Smpp\Exceptions\SocketTimeoutException;
use Smpp\Exceptions\SmppException;

try {
    $messageId = $client->sendSMS($from, $to, $message);
    echo "Sent with ID: $messageId\n";

} catch (SocketTimeoutException $e) {
    // Retry on timeout
    echo "Timeout, retrying...\n";

} catch (SmppException $e) {
    // Check error code for specific handling
    if ($e->getCode() === CommandStatus::ESME_RTHROTTLED) {
        echo "Throttled by SMSC, slow down\n";
    } else {
        echo "SMPP Error: " . $e->getMessage() . "\n";
    }

} catch (\Exception $e) {
    echo "Unexpected error: " . $e->getMessage() . "\n";
}
```

---

## Complete Example

```php
<?php

require 'vendor/autoload.php';

use Smpp\ClientBuilder;
use Smpp\Pdu\Address;
use Smpp\Smpp;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Create logger
$logger = new Logger('smpp');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// Build client
$client = ClientBuilder::createForSockets(['smpp.example.com:2775'])
    ->setSystemId('username')
    ->setPassword('password')
    ->setLogger($logger)
    ->build();

try {
    // Bind as transceiver
    $client->bindTransceiver();

    // Send SMS
    $from = new Address('1234', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
    $to = new Address('5678901234', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);

    $messageId = $client->sendSMS($from, $to, 'Hello from php8-smpp!');
    echo "Message sent with ID: $messageId\n";

    // Query status
    sleep(5);
    $status = $client->queryStatus($messageId, $from);
    if ($status) {
        echo "Message state: " . $status['message_state'] . "\n";
    }

    // Close connection
    $client->close();

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    $client->close();
}
```

---

## Additional Resources

- [SMPP v3.4 Specification](https://smpp.org/SMPP_v3_4_Issue1_2.pdf)
- [GSM 03.38 Character Encoding](https://en.wikipedia.org/wiki/GSM_03.38)
- [3GPP TS 23.040 - SMS Protocol](https://www.3gpp.org/DynaReport/23040.htm)

---

**Last Updated:** 2026-01-22
**Library Version:** 0.1 (Stable API)
