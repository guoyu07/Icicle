<?php
namespace Icicle\Stream;

use Exception;
use Icicle\Promise\Deferred;
use Icicle\Promise\Promise;
use Icicle\Stream\Exception\BusyException;
use Icicle\Stream\Exception\ClosedException;
use Icicle\Stream\Exception\UnreadableException;
use Icicle\Stream\Exception\UnwritableException;
use Icicle\Stream\Structures\Buffer;

/**
 * Serves as buffer that implements the stream interface, allowing consumers to be notified when data is available in
 * the buffer. This class by itself is not particularly useful, but it can be extended to add functionality upon reading 
 * or writing, as well as acting as an example of how stream classes can be implemented.
 */
class Stream implements DuplexStreamInterface
{
    use ParserTrait;

    /**
     * @var \Icicle\Stream\Structures\Buffer
     */
    private $buffer;
    
    /**
     * @var bool
     */
    private $open = true;
    
    /**
     * @var bool
     */
    private $writable = true;
    
    /**
     * @var \Icicle\Promise\Deferred|null
     */
    private $deferred;
    
    /**
     * @var int|null
     */
    private $length;
    
    /**
     * @var string|null
     */
    private $byte;
    
    /**
     * Initializes object structures.
     */
    public function __construct()
    {
        $this->buffer = new Buffer();
    }
    
    /**
     * @inheritdoc
     */
    public function isOpen()
    {
        return $this->open;
    }
    
    /**
     * @inheritdoc
     */
    public function close(Exception $exception = null)
    {
        $this->open = false;
        $this->writable = false;
        
        if (null !== $this->deferred) {
            if (null === $exception) {
                $exception = new ClosedException('The stream was closed.');
            }
            
            $this->deferred->reject($exception);
            $this->deferred = null;
        }
    }
    /**
     * @inheritdoc
     */
    public function read($length = null, $byte = null)
    {
        if (null !== $this->deferred) {
            return Promise::reject(new BusyException('Already waiting on stream.'));
        }
        
        if (!$this->isReadable()) {
            return Promise::reject(new UnreadableException('The stream is no longer readable.'));
        }

        $this->length = $this->parseLength($length);
        $this->byte = $this->parseByte($byte);

        if (!$this->buffer->isEmpty()) {
            if (null !== $this->byte && false !== ($position = $this->buffer->search($this->byte))) {
                if (null === $this->length || $position < $this->length) {
                    return Promise::resolve($this->buffer->remove($position + 1));
                }
                
                return Promise::resolve($this->buffer->remove($this->length));
            }
            
            if (null === $this->length) {
                return Promise::resolve($this->buffer->drain());
            }
            
            return Promise::resolve($this->buffer->remove($this->length));
        }
        
        $this->deferred = new Deferred(function () {
            $this->deferred = null;
        });
        
        return $this->deferred->getPromise();
    }
    
    /**
     * @inheritdoc
     */
    public function isReadable()
    {
        return $this->isOpen();
    }
    
    /**
     * @inheritdoc
     */
    public function poll()
    {
        return $this->read(0);
    }
    
    /**
     * @inheritdoc
     */
    public function write($data)
    {
        if (!$this->isWritable()) {
            return Promise::reject(new UnwritableException('The stream is no longer writable.'));
        }
        
        return $this->send($data);
    }
    
    /**
     * @param   string $data
     *
     * @return  \Icicle\Promise\PromiseInterface
     *
     * @resolve int Number of bytes written to the stream.
     */
    protected function send($data)
    {
        $data = (string) $data; // Single cast in case an object is passed.
        $this->buffer->push($data);
        
        if (null !== $this->deferred && !$this->buffer->isEmpty()) {
            if (null !== $this->byte && false !== ($position = $this->buffer->search($this->byte))) {
                if (null === $this->length || $position < $this->length) {
                    $this->deferred->resolve($this->buffer->remove($position + 1));
                } else {
                    $this->deferred->resolve($this->buffer->remove($this->length));
                }
            } elseif (null === $this->length) {
                $this->deferred->resolve($this->buffer->drain());
            } else {
                $this->deferred->resolve($this->buffer->remove($this->length));
            }
            
            $this->deferred = null;
        }
        
        return Promise::resolve(strlen($data));
    }
    
    /**
     * @inheritdoc
     */
    public function end($data = null)
    {
        $promise = $this->write($data);
        
        $this->writable = false;
        
        $promise->after(function () {
            $this->close();
        });
        
        return $promise;
    }
    
    /**
     * @inheritdoc
     */
    public function await()
    {
        return $this->write(null);
    }
    
    /**
     * @inheritdoc
     */
    public function isWritable()
    {
        return $this->writable;
    }

    /**
     * @inheritdoc
     */
    public function pipe(WritableStreamInterface $stream, $endOnClose = true, $length = null, $byte = null)
    {
        if (!$stream->isWritable()) {
            return Promise::reject(new UnwritableException('The stream is not writable.'));
        }
        
        $length = $this->parseLength($length);
        if (0 === $length) {
            return Promise::resolve(0);
        }

        $byte = $this->parseByte($byte);

        $promise = Promise::iterate(
            function ($data) use (&$length, $stream, $byte) {
                static $bytes = 0;
                $count = strlen($data);
                $bytes += $count;

                $promise = $stream->write($data);

                if ((null !== $byte && $data[$count - 1] === $byte) ||
                    (null !== $length && 0 >= $length -= $count)) {
                    return $promise->always(function () use ($bytes) {
                        return $bytes;
                    });
                }

                return $promise->then(
                    function () use ($length, $byte) {
                        return $this->read($length, $byte);
                    },
                    function () use ($bytes) {
                        return $bytes;
                    }
                );
            },
            function ($data) {
                return is_string($data);
            },
            $this->read($length, $byte)
        );

        if ($endOnClose) {
            $promise->done(null, function () use ($stream) {
                if (!$this->isOpen()) {
                    $stream->end();
                }
            });
        }
        
        return $promise;
    }
}
