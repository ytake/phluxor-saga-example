<?php

declare(strict_types=1);

namespace PhluxorSaga;

use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\ActorSystem\Message\Started;
use Phluxor\ActorSystem\Props;
use Phluxor\ActorSystem\Ref;
use Phluxor\Persistence\InMemoryProvider;
use PhluxorSaga\Internal\ForWithProgress;
use PhluxorSaga\Message\UnknownResult;
use PhluxorSaga\ProtoBuf\FailedAndInconsistent;
use PhluxorSaga\ProtoBuf\FailedButConsistentResult;
use PhluxorSaga\ProtoBuf\SuccessResult;

class Runner implements ActorInterface
{
    private array $transfers = [];
    private int $successResults = 0;
    private int $failedAndInconsistentResults = 0;
    private int $failedButConsistentResults = 0;
    private int $unknownResults = 0;

    public function __construct(
        private readonly int $numberOfIterations,
        private readonly int $intervalBetweenConsoleUpdates,
        private readonly float $uptime,
        private readonly float $refusalProbability,
        private readonly float $busyProbability,
        private readonly int $retryAttempts,
    ) {
    }

    public function receive(ContextInterface $context): void
    {
        $message = $context->message();

        switch (true) {
            case $message instanceof SuccessResult:
                $this->successResults++;
                $this->checkForCompletion($message->getFrom());
                break;

            case $message instanceof UnknownResult:
                $this->unknownResults++;
                $this->checkForCompletion($message->sender);
                break;

            case $message instanceof FailedAndInconsistent:
                $this->failedAndInconsistentResults++;
                $this->checkForCompletion($message->getFrom());
                break;

            case $message instanceof FailedButConsistentResult:
                $this->failedButConsistentResults++;
                $this->checkForCompletion($message->getFrom());
                break;

            case $message instanceof Started:
                $inMemoryProvider = $this->inMemoryProvider();
                (new ForWithProgress(
                    $this->numberOfIterations,
                    $this->intervalBetweenConsoleUpdates,
                    true,
                    false
                ))->everyNth(
                    fn($i) => print("Started {$i}/{$this->numberOfIterations} processes\n"),
                    function ($i, $nth) use ($context, $inMemoryProvider) {
                        $fromAccount = $this->createAccount($context, "FromAccount{$i}");
                        $toAccount = $this->createAccount($context, "ToAccount{$i}");
                        $actorName = "Transfer Process {$i}";
                        $factory = new TransferFactory(
                            $context,
                            $this->uptime,
                            $this->retryAttempts,
                            $inMemoryProvider
                        );
                        $transfer = $factory->createTransfer($actorName, $fromAccount, $toAccount, 10);
                        $this->transfers[] = $transfer;

                        if ($i === $this->numberOfIterations && !$nth) {
                            print("Started {$i}/{$this->numberOfIterations} processes\n");
                        }
                    }
                );
                break;
        }
    }

    private function checkForCompletion(Ref $pid): void
    {
        $this->transfers = array_filter($this->transfers, fn($t) => $t !== $pid);
        $remaining = count($this->transfers);

        if ($this->numberOfIterations >= $this->intervalBetweenConsoleUpdates) {
            print(".");
            if ($remaining % ($this->numberOfIterations / $this->intervalBetweenConsoleUpdates) === 0) {
                print("\n{$remaining} processes remaining\n");
            }
        } else {
            print("{$remaining} processes remaining\n");
        }

        if ($remaining === 0) {
            usleep(250000);
            print("\nRESULTS:\n");
            printf(
                "%.2f%% (%d/%d) successful transfers\n",
                $this->asPercentage($this->successResults),
                $this->successResults,
                $this->numberOfIterations
            );
            printf(
                "%.2f%% (%d/%d) failures leaving a consistent system\n",
                $this->asPercentage($this->failedButConsistentResults),
                $this->failedButConsistentResults,
                $this->numberOfIterations
            );
            printf(
                "%.2f%% (%d/%d) failures leaving an inconsistent system\n",
                $this->asPercentage($this->failedAndInconsistentResults),
                $this->failedAndInconsistentResults,
                $this->numberOfIterations
            );
            printf(
                "%.2f%% (%d/%d) unknown results\n",
                $this->asPercentage($this->unknownResults),
                $this->unknownResults,
                $this->numberOfIterations
            );
        }
    }

    private function asPercentage(int $results): float
    {
        return ($results / $this->numberOfIterations) * 100;
    }

    private function inMemoryProvider(): InMemoryStateProvider
    {
        return new InMemoryStateProvider(new InMemoryProvider(1));
    }

    private function createAccount(ContextInterface $context, string $name): Ref
    {
        $accountProps = Props::fromProducer(
            fn() => new Account($this->uptime, $this->refusalProbability, $this->busyProbability)
        );
        return $context->spawnNamed($accountProps, $name)->getRef();
    }
}
