<?php
use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\FormatterInterface;
use Monolog\Logger;
use Raven_Client;

class Raven_BreadcrumbHandler extends AbstractProcessingHandler
{
    /**
     * Translates Monolog log levels to Raven log levels.
     */
    private $logLevels = array(
        Logger::DEBUG     => Raven_Client::DEBUG,
        Logger::INFO      => Raven_Client::INFO,
        Logger::NOTICE    => Raven_Client::INFO,
        Logger::WARNING   => Raven_Client::WARNING,
        Logger::ERROR     => Raven_Client::ERROR,
        Logger::CRITICAL  => Raven_Client::FATAL,
        Logger::ALERT     => Raven_Client::FATAL,
        Logger::EMERGENCY => Raven_Client::FATAL,
    );

    /**
     * @var Raven_Client the client object that sends the message to the server
     */
    protected $ravenClient;

    /**
     * @var LineFormatter The formatter to use for the logs generated via handleBatch()
     */
    protected $batchFormatter;

    /**
     * @param Raven_Client $ravenClient
     * @param int          $level       The minimum logging level at which this handler will be triggered
     * @param Boolean      $bubble      Whether the messages that are handled can bubble up the stack or not
     */
    public function __construct(Raven_Client $ravenClient, $level = Logger::DEBUG, $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->ravenClient = $ravenClient;
    }

    /**
     * {@inheritdoc}
     */
    public function handleBatch(array $records)
    {
        $level = $this->level;

        // filter records based on their level
        $records = array_filter($records, function ($record) use ($level) {
            return $record['level'] >= $level;
        });

        if (!$records) {
            return;
        }

        // the record with the highest severity is the "main" one
        $record = array_reduce($records, function ($highest, $record) {
            if ($record['level'] >= $highest['level']) {
                return $record;
            }

            return $highest;
        });

        // the other ones are added as a context item
        $logs = array();
        foreach ($records as $r) {
            $logs[] = $this->processRecord($r);
        }

        if ($logs) {
            $record['context']['logs'] = (string) $this->getBatchFormatter()->formatBatch($logs);
        }

        $this->handle($record);
    }

    /**
     * Sets the formatter for the logs generated by handleBatch().
     *
     * @param FormatterInterface $formatter
     */
    public function setBatchFormatter(FormatterInterface $formatter)
    {
        $this->batchFormatter = $formatter;
    }

    /**
     * Gets the formatter for the logs generated by handleBatch().
     *
     * @return FormatterInterface
     */
    public function getBatchFormatter()
    {
        if (!$this->batchFormatter) {
            $this->batchFormatter = $this->getDefaultBatchFormatter();
        }

        return $this->batchFormatter;
    }

    /**
     * {@inheritdoc}
     */
    protected function write(array $record)
    {
        // TODO(dcramer): support $record['context']['exception'] instanceof \Exception
        $crumb = array(
            'level' => $this->logLevels[$record['level']],
            'category' => $record['channel'],
            'message' => $record['formatted'],
        );

        $this->ravenClient->breadcrumbs->record($crumb);
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LineFormatter('%message%');
    }

    /**
     * Gets the default formatter for the logs generated by handleBatch().
     *
     * @return FormatterInterface
     */
    protected function getDefaultBatchFormatter()
    {
        return new LineFormatter();
    }

    /**
     * Gets extra parameters supported by Raven that can be found in "extra" and "context"
     *
     * @return array
     */
    protected function getExtraParameters()
    {
        return array();
    }
}
