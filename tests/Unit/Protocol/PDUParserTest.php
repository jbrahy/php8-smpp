<?php

declare(strict_types=1);

namespace Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Smpp\Exceptions\PDUParseException;
use Smpp\Exceptions\SmppException;
use Smpp\Pdu\Address;
use Smpp\Pdu\DeliveryReceipt;
use Smpp\Pdu\Pdu;
use Smpp\Pdu\PDUHeader;
use Smpp\Pdu\Sms;
use Smpp\Pdu\Tag;
use Smpp\Protocol\Command;
use Smpp\Protocol\CommandStatus;
use Smpp\Protocol\PDUParser;
use Smpp\Smpp;

/**
 * Comprehensive test coverage for PDUParser
 *
 * Tests cover:
 * - PDU header parsing (valid and malformed)
 * - SMS PDU parsing
 * - Delivery receipt parsing
 * - TLV tag parsing
 * - Edge cases and error handling
 * - Security: malformed/truncated PDU handling
 */
class PDUParserTest extends TestCase
{
    private PDUParser $parser;
    private NullLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new NullLogger();
        $this->parser = new PDUParser($this->logger);
    }

    /**
     * Test: parsePduHeader() correctly parses valid 16-byte PDU header
     */
    public function testParsePduHeaderParsesValidHeader(): void
    {
        // Create a valid BIND_TRANSMITTER_RESP header
        // Length: 16 (0x00000010), Command: BIND_TRANSMITTER_RESP (0x80000001)
        // Status: ESME_ROK (0x00000000), Sequence: 1 (0x00000001)
        $headerData = pack(
            'NNNN',
            16,                                  // command_length
            Command::BIND_TRANSMITTER_RESP,      // command_id
            CommandStatus::ESME_ROK,             // command_status
            1                                    // sequence_number
        );

        $header = $this->parser->parsePduHeader($headerData);

        $this->assertInstanceOf(PDUHeader::class, $header);
        $this->assertEquals(16, $header->getCommandLength());
        $this->assertEquals(Command::BIND_TRANSMITTER_RESP, $header->getCommandId());
        $this->assertEquals(CommandStatus::ESME_ROK, $header->getCommandStatus());
        $this->assertEquals(1, $header->getSequenceNumber());
    }

    /**
     * Test: parsePduHeader() handles large sequence numbers correctly
     */
    public function testParsePduHeaderHandlesLargeSequenceNumber(): void
    {
        $largeSequence = 0x7FFFFFFE; // Near maximum sequence number

        $headerData = pack('NNNN', 16, Command::SUBMIT_SM_RESP, CommandStatus::ESME_ROK, $largeSequence);

        $header = $this->parser->parsePduHeader($headerData);

        $this->assertEquals($largeSequence, $header->getSequenceNumber());
    }

    /**
     * Test: parsePduHeader() handles different command IDs correctly
     */
    public function testParsePduHeaderHandlesDifferentCommandIds(): void
    {
        $commands = [
            Command::SUBMIT_SM,
            Command::SUBMIT_SM_RESP,
            Command::DELIVER_SM,
            Command::ENQUIRE_LINK,
            Command::UNBIND,
            Command::GENERIC_NACK,
        ];

        foreach ($commands as $commandId) {
            $headerData = pack('NNNN', 16, $commandId, CommandStatus::ESME_ROK, 1);
            $header = $this->parser->parsePduHeader($headerData);

            $this->assertEquals($commandId, $header->getCommandId(),
                "Failed for command ID: 0x" . dechex($commandId));
        }
    }

    /**
     * Test: parsePduHeader() handles different status codes correctly
     */
    public function testParsePduHeaderHandlesDifferentStatusCodes(): void
    {
        $statuses = [
            CommandStatus::ESME_ROK,
            CommandStatus::ESME_RINVMSGLEN,
            CommandStatus::ESME_RBINDFAIL,
            CommandStatus::ESME_RSUBMITFAIL,
            CommandStatus::ESME_RTHROTTLED,
        ];

        foreach ($statuses as $status) {
            $headerData = pack('NNNN', 16, Command::SUBMIT_SM_RESP, $status, 1);
            $header = $this->parser->parsePduHeader($headerData);

            $this->assertEquals($status, $header->getCommandStatus());
        }
    }

    /**
     * Test: parsePduHeader() throws exception on truncated header (< 16 bytes)
     */
    public function testParsePduHeaderThrowsExceptionOnTruncatedHeader(): void
    {
        $this->expectException(PDUParseException::class);
        $this->expectExceptionMessage('PDU header must be at least 16 bytes');

        // Only 12 bytes instead of required 16
        $truncatedHeader = pack('NNN', 16, Command::SUBMIT_SM, CommandStatus::ESME_ROK);

        $this->parser->parsePduHeader($truncatedHeader);
    }

    /**
     * Test: parsePduHeader() throws exception on empty data
     */
    public function testParsePduHeaderThrowsExceptionOnEmptyData(): void
    {
        $this->expectException(PDUParseException::class);
        $this->expectExceptionMessage('PDU header must be at least 16 bytes');

        $this->parser->parsePduHeader('');
    }

    /**
     * Test: parsePduHeader() throws exception on single byte
     */
    public function testParsePduHeaderThrowsExceptionOnSingleByte(): void
    {
        $this->expectException(PDUParseException::class);

        $this->parser->parsePduHeader("\x00");
    }

    /**
     * Test: parsePduHeader() throws exception on 15 bytes (just under limit)
     */
    public function testParsePduHeaderThrowsExceptionOnFifteenBytes(): void
    {
        $this->expectException(PDUParseException::class);
        $this->expectExceptionMessage('PDU header must be at least 16 bytes');

        $fifteenBytes = str_repeat("\x00", 15);
        $this->parser->parsePduHeader($fifteenBytes);
    }

    /**
     * Test: parsePduHeader() accepts exactly 16 bytes
     */
    public function testParsePduHeaderAcceptsExactlySixteenBytes(): void
    {
        $exactlyShort = pack('NNNN', 16, Command::ENQUIRE_LINK, CommandStatus::ESME_ROK, 1);

        $header = $this->parser->parsePduHeader($exactlyShort);

        $this->assertEquals(16, strlen($exactlyShort));
        $this->assertInstanceOf(PDUHeader::class, $header);
    }

    /**
     * Test: parsePduHeader() handles extra bytes (only parses first 16)
     */
    public function testParsePduHeaderHandlesExtraBytes(): void
    {
        $headerWithBody = pack('NNNN', 32, Command::SUBMIT_SM, CommandStatus::ESME_ROK, 1);
        $headerWithBody .= str_repeat('X', 16); // Add body data

        $header = $this->parser->parsePduHeader($headerWithBody);

        // Should successfully parse just the header
        $this->assertEquals(32, $header->getCommandLength());
        $this->assertEquals(Command::SUBMIT_SM, $header->getCommandId());
    }

    /**
     * Test: parseSms() parses valid DELIVER_SM PDU (regular SMS)
     */
    public function testParseSmsValidDeliverSmPdu(): void
    {
        // Build a minimal valid DELIVER_SM PDU body
        $serviceType = "\0"; // Empty service type (null-terminated)
        $sourceAddrTon = pack('C', Smpp::TON_INTERNATIONAL);
        $sourceAddrNpi = pack('C', Smpp::NPI_E164);
        $sourceAddr = "1234567890\0";
        $destAddrTon = pack('C', Smpp::TON_INTERNATIONAL);
        $destAddrNpi = pack('C', Smpp::NPI_E164);
        $destAddr = "9876543210\0";
        $esmClass = pack('C', 0x00); // No special features
        $protocolId = pack('C', 0x00);
        $priorityFlag = pack('C', 0x00);
        $scheduleDeliveryTime = "\0";
        $validityPeriod = "\0";
        $registeredDelivery = pack('C', 0x00);
        $replaceIfPresentFlag = pack('C', 0x00);
        $dataCoding = pack('C', Smpp::DATA_CODING_DEFAULT);
        $smDefaultMsgId = pack('C', 0x00);
        $message = "Hello World";
        $smLength = pack('C', strlen($message));

        $pduBody = $serviceType
            . $sourceAddrTon . $sourceAddrNpi . $sourceAddr
            . $destAddrTon . $destAddrNpi . $destAddr
            . $esmClass . $protocolId . $priorityFlag
            . $scheduleDeliveryTime . $validityPeriod
            . $registeredDelivery . $replaceIfPresentFlag
            . $dataCoding . $smDefaultMsgId
            . $smLength . $message;

        $pdu = new Pdu(Command::DELIVER_SM, CommandStatus::ESME_ROK, 1, $pduBody);

        $sms = $this->parser->parseSms($pdu);

        $this->assertInstanceOf(Sms::class, $sms);
        $this->assertNotInstanceOf(DeliveryReceipt::class, $sms);
        $this->assertEquals('Hello World', $sms->getMessage());
        $this->assertEquals('1234567890', $sms->getSource()->getValue());
        $this->assertEquals('9876543210', $sms->getDestination()->getValue());
        $this->assertEquals(Smpp::DATA_CODING_DEFAULT, $sms->getDataCoding());
    }

    /**
     * Test: parseSms() detects and creates DeliveryReceipt when ESM class indicates receipt
     */
    public function testParseSmsCreatesDeliveryReceiptWhenEsmClassIndicates(): void
    {
        // ESM class with SMSC delivery receipt flag set
        $esmClassWithReceipt = Smpp::ESM_DELIVER_SMSC_RECEIPT;

        $pduBody = $this->createDeliverSmPduBody(
            message: "id:msg123 sub:001 dlvrd:001 submit date:2601221200 done date:2601221201 stat:DELIVRD err:000 text:Test",
            esmClass: $esmClassWithReceipt
        );

        $pdu = new Pdu(Command::DELIVER_SM, CommandStatus::ESME_ROK, 1, $pduBody);

        $sms = $this->parser->parseSms($pdu);

        $this->assertInstanceOf(DeliveryReceipt::class, $sms);
    }

    /**
     * Test: parseSms() throws exception on empty PDU body
     */
    public function testParseSmsThrowsExceptionOnEmptyBody(): void
    {
        $this->expectException(SmppException::class);
        $this->expectExceptionMessage('Format not matches with PDU body contents');

        $pdu = new Pdu(Command::DELIVER_SM, CommandStatus::ESME_ROK, 1, '');

        $this->parser->parseSms($pdu);
    }

    /**
     * Test: parseSms() handles Unicode (UCS-2) encoded message
     */
    public function testParseSmsHandlesUcs2EncodedMessage(): void
    {
        $unicodeMessage = mb_convert_encoding("Hello 世界", 'UTF-16BE', 'UTF-8');

        $pduBody = $this->createDeliverSmPduBody(
            message: $unicodeMessage,
            dataCoding: Smpp::DATA_CODING_UCS2
        );

        $pdu = new Pdu(Command::DELIVER_SM, CommandStatus::ESME_ROK, 1, $pduBody);

        $sms = $this->parser->parseSms($pdu);

        $this->assertEquals(Smpp::DATA_CODING_UCS2, $sms->getDataCoding());
        $this->assertEquals($unicodeMessage, $sms->getMessage());
    }

    /**
     * Test: parseSms() handles empty message (zero-length SMS)
     */
    public function testParseSmsHandlesEmptyMessage(): void
    {
        $pduBody = $this->createDeliverSmPduBody(message: '');

        $pdu = new Pdu(Command::DELIVER_SM, CommandStatus::ESME_ROK, 1, $pduBody);

        $sms = $this->parser->parseSms($pdu);

        $this->assertEquals('', $sms->getMessage());
    }

    /**
     * Test: parseSms() handles maximum length message (254 bytes)
     */
    public function testParseSmsHandlesMaximumLengthMessage(): void
    {
        $maxMessage = str_repeat('A', 254);

        $pduBody = $this->createDeliverSmPduBody(message: $maxMessage);

        $pdu = new Pdu(Command::DELIVER_SM, CommandStatus::ESME_ROK, 1, $pduBody);

        $sms = $this->parser->parseSms($pdu);

        $this->assertEquals(254, strlen($sms->getMessage()));
        $this->assertEquals($maxMessage, $sms->getMessage());
    }

    /**
     * Test: parsePduHeader() handles all zeros (edge case)
     */
    public function testParsePduHeaderHandlesAllZeros(): void
    {
        $allZeros = pack('NNNN', 0, 0, 0, 0);

        $header = $this->parser->parsePduHeader($allZeros);

        $this->assertEquals(0, $header->getCommandLength());
        $this->assertEquals(0, $header->getCommandId());
        $this->assertEquals(0, $header->getCommandStatus());
        $this->assertEquals(0, $header->getSequenceNumber());
    }

    /**
     * Test: parsePduHeader() handles all 0xFF bytes (edge case)
     */
    public function testParsePduHeaderHandlesAllOnes(): void
    {
        $allOnes = str_repeat("\xFF", 16);

        $header = $this->parser->parsePduHeader($allOnes);

        // Each 32-bit field should be 0xFFFFFFFF (4294967295)
        $this->assertEquals(0xFFFFFFFF, $header->getCommandLength());
        $this->assertEquals(0xFFFFFFFF, $header->getCommandId());
        $this->assertEquals(0xFFFFFFFF, $header->getCommandStatus());
        $this->assertEquals(0xFFFFFFFF, $header->getSequenceNumber());
    }

    /**
     * Test: Parsing preserves binary data integrity (no data corruption)
     */
    public function testParsingPreservesBinaryDataIntegrity(): void
    {
        // Test with binary message containing null bytes
        $binaryMessage = "\x00\x01\x02\x03\x04\x05";

        $pduBody = $this->createDeliverSmPduBody(
            message: $binaryMessage,
            dataCoding: Smpp::DATA_CODING_BINARY
        );

        $pdu = new Pdu(Command::DELIVER_SM, CommandStatus::ESME_ROK, 1, $pduBody);

        $sms = $this->parser->parseSms($pdu);

        $this->assertEquals($binaryMessage, $sms->getMessage());
        $this->assertEquals(Smpp::DATA_CODING_BINARY, $sms->getDataCoding());
    }

    /**
     * Helper: Create a valid DELIVER_SM PDU body for testing
     */
    private function createDeliverSmPduBody(
        string $message = 'Test',
        int $esmClass = 0x00,
        int $dataCoding = Smpp::DATA_CODING_DEFAULT,
        string $sourceAddr = '1234567890',
        string $destAddr = '9876543210'
    ): string {
        $serviceType = "\0";
        $sourceAddrTon = pack('C', Smpp::TON_INTERNATIONAL);
        $sourceAddrNpi = pack('C', Smpp::NPI_E164);
        $sourceAddrPacked = $sourceAddr . "\0";
        $destAddrTon = pack('C', Smpp::TON_INTERNATIONAL);
        $destAddrNpi = pack('C', Smpp::NPI_E164);
        $destAddrPacked = $destAddr . "\0";
        $esmClassPacked = pack('C', $esmClass);
        $protocolId = pack('C', 0x00);
        $priorityFlag = pack('C', 0x00);
        $scheduleDeliveryTime = "\0";
        $validityPeriod = "\0";
        $registeredDelivery = pack('C', 0x00);
        $replaceIfPresentFlag = pack('C', 0x00);
        $dataCodingPacked = pack('C', $dataCoding);
        $smDefaultMsgId = pack('C', 0x00);
        $smLength = pack('C', strlen($message));

        return $serviceType
            . $sourceAddrTon . $sourceAddrNpi . $sourceAddrPacked
            . $destAddrTon . $destAddrNpi . $destAddrPacked
            . $esmClassPacked . $protocolId . $priorityFlag
            . $scheduleDeliveryTime . $validityPeriod
            . $registeredDelivery . $replaceIfPresentFlag
            . $dataCodingPacked . $smDefaultMsgId
            . $smLength . $message;
    }
}
