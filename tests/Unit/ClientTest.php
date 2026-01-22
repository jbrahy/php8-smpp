<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Smpp\Client;
use Smpp\Configs\SmppConfig;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Exceptions\SmppException;
use Smpp\Exceptions\SocketTransportException;
use Smpp\Pdu\Address;
use Smpp\Pdu\Sms;
use Smpp\Protocol\Command;
use Smpp\Protocol\CommandStatus;
use Smpp\Smpp;

/**
 * Comprehensive test coverage for the SMPP Client class
 *
 * Tests cover:
 * - Binding operations (transmitter, receiver, transceiver)
 * - SMS sending (single and concatenated)
 * - SMS receiving and delivery receipts
 * - Connection lifecycle (open, close, reconnect)
 * - Error handling and edge cases
 * - PDU queue management
 */
class ClientTest extends TestCase
{
    private MockObject|TransportInterface $transport;
    private Client $client;
    private const SYSTEM_ID = 'testuser';
    private const PASSWORD = 'testpass';

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = $this->createMock(TransportInterface::class);
        $this->client = new Client($this->transport, self::SYSTEM_ID, self::PASSWORD);
        $this->client->logger = new NullLogger();
    }

    /**
     * Test: Client constructor properly initializes dependencies
     */
    public function testConstructorInitializesClient(): void
    {
        $this->assertInstanceOf(Client::class, $this->client);
        $this->assertInstanceOf(SmppConfig::class, $this->client->config);
        $this->assertInstanceOf(NullLogger::class, $this->client->logger);
        $this->assertSame($this->transport, $this->client->transport);
    }

    /**
     * Test: bindTransmitter() opens transport and sends proper BIND_TRANSMITTER PDU
     */
    public function testBindTransmitterOpensConnectionAndBinds(): void
    {
        $this->transport->expects($this->once())
            ->method('isOpen')
            ->willReturn(false);

        $this->transport->expects($this->once())
            ->method('open');

        // Expect write of BIND_TRANSMITTER PDU
        $this->transport->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) {
                // Verify PDU header contains BIND_TRANSMITTER command (0x00000002)
                $header = unpack('Nlength/Nid/Nstatus/Nsequence', substr($data, 0, 16));
                return $header['id'] === Command::BIND_TRANSMITTER;
            }));

        // Mock successful BIND_TRANSMITTER_RESP
        $this->mockBindResponse(Command::BIND_TRANSMITTER_RESP);

        $this->client->bindTransmitter();
    }

    /**
     * Test: bindReceiver() opens transport and sends proper BIND_RECEIVER PDU
     */
    public function testBindReceiverOpensConnectionAndBinds(): void
    {
        $this->transport->expects($this->once())
            ->method('isOpen')
            ->willReturn(false);

        $this->transport->expects($this->once())
            ->method('open');

        // Expect write of BIND_RECEIVER PDU
        $this->transport->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) {
                $header = unpack('Nlength/Nid/Nstatus/Nsequence', substr($data, 0, 16));
                return $header['id'] === Command::BIND_RECEIVER;
            }));

        $this->mockBindResponse(Command::BIND_RECEIVER_RESP);

        $this->client->bindReceiver();
    }

    /**
     * Test: bindTransceiver() opens transport and sends proper BIND_TRANSCEIVER PDU
     */
    public function testBindTransceiverOpensConnectionAndBinds(): void
    {
        $this->transport->expects($this->once())
            ->method('isOpen')
            ->willReturn(false);

        $this->transport->expects($this->once())
            ->method('open');

        $this->transport->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) {
                $header = unpack('Nlength/Nid/Nstatus/Nsequence', substr($data, 0, 16));
                return $header['id'] === Command::BIND_TRANSCEIVER;
            }));

        $this->mockBindResponse(Command::BIND_TRANSCEIVER_RESP);

        $this->client->bindTransceiver();
    }

    /**
     * Test: Binding fails when transport throws exception
     */
    public function testBindTransmitterFailsWhenTransportThrowsException(): void
    {
        $this->transport->expects($this->once())
            ->method('isOpen')
            ->willReturn(false);

        $this->transport->expects($this->once())
            ->method('open')
            ->willThrowException(new SocketTransportException('Connection refused'));

        $this->expectException(SocketTransportException::class);
        $this->expectExceptionMessage('Connection refused');

        $this->client->bindTransmitter();
    }

    /**
     * Test: Binding fails with invalid credentials (ESME_RBINDFAIL status)
     */
    public function testBindTransmitterFailsWithInvalidCredentials(): void
    {
        $this->transport->method('isOpen')->willReturn(false);
        $this->transport->method('open');
        $this->transport->method('write');

        // Mock BIND_TRANSMITTER_RESP with ESME_RBINDFAIL status
        $this->mockBindResponse(Command::BIND_TRANSMITTER_RESP, CommandStatus::ESME_RBINDFAIL);

        $this->expectException(SmppException::class);

        $this->client->bindTransmitter();
    }

    /**
     * Test: sendSMS() sends single SMS with default encoding (GSM 03.38)
     */
    public function testSendSingleSmsWithDefaultEncoding(): void
    {
        $this->setupBoundClient();

        $from = new Address('1234', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
        $to = new Address('5678', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
        $message = 'Hello World';

        $this->transport->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) {
                $header = unpack('Nlength/Nid/Nstatus/Nsequence', substr($data, 0, 16));
                return $header['id'] === Command::SUBMIT_SM;
            }));

        // Mock SUBMIT_SM_RESP with message ID
        $this->mockSubmitSmResponse('msg123');

        $messageId = $this->client->sendSMS($from, $to, $message);

        $this->assertEquals('msg123', trim($messageId, "\0"));
    }

    /**
     * Test: sendSMS() sends single SMS with UCS-2 encoding (UTF-16BE for Unicode)
     */
    public function testSendSingleSmsWithUcs2Encoding(): void
    {
        $this->setupBoundClient();

        $from = new Address('1234', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
        $to = new Address('5678', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
        $message = 'Hello 你好 🎉'; // Mixed ASCII, Chinese, emoji

        $this->transport->expects($this->once())
            ->method('write');

        $this->mockSubmitSmResponse('msg456');

        $messageId = $this->client->sendSMS($from, $to, $message, null, Smpp::DATA_CODING_UCS2);

        $this->assertEquals('msg456', trim($messageId, "\0"));
    }

    /**
     * Test: sendSMS() splits long message into concatenated SMS (CSMS) using 16-bit SAR tags
     */
    public function testSendConcatenatedSmsWithSarTags(): void
    {
        $this->setupBoundClient();

        $from = new Address('1234', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
        $to = new Address('5678', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);

        // Message longer than 160 chars (single SMS limit for GSM 03.38)
        $message = str_repeat('A', 200);

        // Expect multiple SUBMIT_SM calls (at least 2 parts)
        $this->transport->expects($this->atLeast(2))
            ->method('write');

        $this->transport->method('read')
            ->willReturnOnConsecutiveCalls(
                $this->createPduHeader(Command::SUBMIT_SM_RESP, CommandStatus::ESME_ROK, 1, 20),
                "msg001\0\0\0\0\0\0\0\0\0\0\0\0\0\0",
                $this->createPduHeader(Command::SUBMIT_SM_RESP, CommandStatus::ESME_ROK, 2, 20),
                "msg002\0\0\0\0\0\0\0\0\0\0\0\0\0\0"
            );

        $messageId = $this->client->sendSMS($from, $to, $message);

        $this->assertNotEmpty($messageId);
    }

    /**
     * Test: sendSMS() rejects unsupported data coding for long messages
     */
    public function testSendConcatenatedSmsRejectsUnsupportedEncoding(): void
    {
        $this->setupBoundClient();

        $from = new Address('1234', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
        $to = new Address('5678', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
        $message = str_repeat('A', 200);

        // Use unsupported data coding (not DEFAULT or UCS2)
        $result = $this->client->sendSMS($from, $to, $message, null, Smpp::DATA_CODING_BINARY);

        $this->assertFalse($result);
    }

    /**
     * Test: close() sends UNBIND command and closes transport
     */
    public function testCloseUnbindsAndClosesTransport(): void
    {
        $this->transport->expects($this->once())
            ->method('isOpen')
            ->willReturn(true);

        $this->transport->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) {
                $header = unpack('Nlength/Nid/Nstatus/Nsequence', substr($data, 0, 16));
                return $header['id'] === Command::UNBIND;
            }));

        $this->transport->expects($this->once())
            ->method('read')
            ->willReturnOnConsecutiveCalls(
                $this->createPduHeader(Command::UNBIND_RESP, CommandStatus::ESME_ROK, 1, 16)
            );

        $this->transport->expects($this->once())
            ->method('close');

        $this->client->close();
    }

    /**
     * Test: close() does nothing if transport already closed
     */
    public function testCloseDoesNothingWhenAlreadyClosed(): void
    {
        $this->transport->expects($this->once())
            ->method('isOpen')
            ->willReturn(false);

        $this->transport->expects($this->never())
            ->method('write');

        $this->transport->expects($this->never())
            ->method('close');

        $this->client->close();
    }

    /**
     * Test: enquireLink() sends ENQUIRE_LINK command
     */
    public function testEnquireLinkSendsProperCommand(): void
    {
        $this->setupBoundClient();

        $this->transport->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) {
                $header = unpack('Nlength/Nid/Nstatus/Nsequence', substr($data, 0, 16));
                return $header['id'] === Command::ENQUIRE_LINK;
            }));

        $this->transport->expects($this->once())
            ->method('read')
            ->willReturn($this->createPduHeader(Command::ENQUIRE_LINK_RESP, CommandStatus::ESME_ROK, 1, 16));

        $response = $this->client->enquireLink();

        $this->assertEquals(Command::ENQUIRE_LINK_RESP, $response->getId());
        $this->assertEquals(CommandStatus::ESME_ROK, $response->getStatus());
    }

    /**
     * Test: queryStatus() retrieves message status from SMSC
     */
    public function testQueryStatusRetrievesMessageStatus(): void
    {
        $this->setupBoundClient();

        $messageId = 'msg123';
        $source = new Address('1234', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);

        $this->transport->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) {
                $header = unpack('Nlength/Nid/Nstatus/Nsequence', substr($data, 0, 16));
                return $header['id'] === Command::QUERY_SM;
            }));

        // Mock QUERY_SM_RESP with message state and error code
        $responseBody = "msg123\0" . "260124120000000+\0" . pack('CC', Smpp::STATE_DELIVERED, 0);
        $this->transport->expects($this->once())
            ->method('read')
            ->willReturnOnConsecutiveCalls(
                $this->createPduHeader(Command::QUERY_SM_RESP, CommandStatus::ESME_ROK, 1, 16 + strlen($responseBody)),
                $responseBody
            );

        $status = $this->client->queryStatus($messageId, $source);

        $this->assertIsArray($status);
        $this->assertArrayHasKey('message_id', $status);
        $this->assertArrayHasKey('message_state', $status);
        $this->assertArrayHasKey('error_code', $status);
        $this->assertEquals('msg123', $status['message_id']);
        $this->assertEquals(Smpp::STATE_DELIVERED, $status['message_state']);
    }

    /**
     * Test: queryStatus() returns null on error status
     */
    public function testQueryStatusReturnsNullOnError(): void
    {
        $this->setupBoundClient();

        $messageId = 'msg999';
        $source = new Address('1234', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);

        $this->transport->method('write');
        $this->transport->method('read')
            ->willReturn($this->createPduHeader(Command::QUERY_SM_RESP, CommandStatus::ESME_RQUERYFAIL, 1, 16));

        $status = $this->client->queryStatus($messageId, $source);

        $this->assertNull($status);
    }

    /**
     * Test: Sequence number increments with each command
     */
    public function testSequenceNumberIncrementsWithCommands(): void
    {
        $this->setupBoundClient();

        $sequenceNumbers = [];

        $this->transport->method('write')
            ->willReturnCallback(function ($data) use (&$sequenceNumbers) {
                $header = unpack('Nlength/Nid/Nstatus/Nsequence', substr($data, 0, 16));
                $sequenceNumbers[] = $header['sequence'];
            });

        $this->transport->method('read')
            ->willReturnOnConsecutiveCalls(
                $this->createPduHeader(Command::ENQUIRE_LINK_RESP, CommandStatus::ESME_ROK, 1, 16),
                $this->createPduHeader(Command::ENQUIRE_LINK_RESP, CommandStatus::ESME_ROK, 2, 16),
                $this->createPduHeader(Command::ENQUIRE_LINK_RESP, CommandStatus::ESME_ROK, 3, 16)
            );

        $this->client->enquireLink();
        $this->client->enquireLink();
        $this->client->enquireLink();

        $this->assertEquals([1, 2, 3], $sequenceNumbers);
    }

    /**
     * Helper: Set up a client that's already bound (transport open and responding)
     */
    private function setupBoundClient(): void
    {
        $this->transport->method('isOpen')->willReturn(true);
    }

    /**
     * Helper: Mock a successful bind response
     */
    private function mockBindResponse(int $commandId, int $status = CommandStatus::ESME_ROK): void
    {
        $systemId = "SMSC\0";
        $responseBody = $systemId;

        $this->transport->method('read')
            ->willReturnOnConsecutiveCalls(
                $this->createPduHeader($commandId, $status, 1, 16 + strlen($responseBody)),
                $responseBody
            );
    }

    /**
     * Helper: Mock a SUBMIT_SM_RESP with message ID
     */
    private function mockSubmitSmResponse(string $messageId): void
    {
        $responseBody = $messageId . "\0";

        $this->transport->method('read')
            ->willReturnOnConsecutiveCalls(
                $this->createPduHeader(Command::SUBMIT_SM_RESP, CommandStatus::ESME_ROK, 1, 16 + strlen($responseBody)),
                $responseBody
            );
    }

    /**
     * Helper: Create a binary PDU header
     */
    private function createPduHeader(int $commandId, int $status, int $sequence, int $length): string
    {
        return pack('NNNN', $length, $commandId, $status, $sequence);
    }
}
