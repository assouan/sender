<?php

declare(strict_types=1);

namespace A\Http\Protocol\Http2;

class Frame
{
    public const DATA = 0;
    public const HEADERS = 1;
    public const RST_STREAM = 3;
    public const SETTINGS = 4;
    public const GOAWAY = 7;
    public const WINDOW_UPDATE = 8;

    public const END_STREAM = 0x01;
    public const ACK = 0x01;
    public const END_HEADERS = 0x04;

    protected(set) int $type;

    protected(set) int $flags;

    protected(set) int $stream_id;

    protected(set) string $payload;

    public function __construct(int $type, int $flags, int $stream_id, string $payload = '')
    {
        $this->type = $type;
        $this->flags = $flags;
        $this->stream_id = $stream_id;
        $this->payload = $payload;
    }

    public static function encode(int $type, int $flags, int $stream_id, string $payload = '') : string
    {
        $length = strlen($payload);

        return chr(($length >> 16) & 0xff)
            . chr(($length >> 8) & 0xff)
            . chr($length & 0xff)
            . chr($type)
            . chr($flags)
            . pack('N', $stream_id & 0x7fffffff)
            . $payload;
    }

    public static function try_decode(string $buffer) : ?array
    {
        if (strlen($buffer) < 9)
        {
            return null;
        }

        $length = (ord($buffer[0]) << 16) | (ord($buffer[1]) << 8) | ord($buffer[2]);

        if (strlen($buffer) < 9 + $length)
        {
            return null;
        }

        $stream_id = unpack('N', substr($buffer, 5, 4))[1] & 0x7fffffff;
        $frame = new static(ord($buffer[3]), ord($buffer[4]), $stream_id, substr($buffer, 9, $length));

        return [$frame, substr($buffer, 9 + $length)];
    }
}
