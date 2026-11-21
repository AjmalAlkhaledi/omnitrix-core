<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Notifications;

use Omnitrix\Application;
use Omnitrix\Database\Migrations\Migrator;
use Omnitrix\Database\Model;
use Omnitrix\Database\Schema\Blueprint;
use Omnitrix\Database\Schema\Schema;
use Omnitrix\Events\ShouldQueue;
use Omnitrix\Facades\DB;
use Omnitrix\Facades\Facade;
use Omnitrix\Facades\Notification as Notify;
use Omnitrix\Mail\Mailer;
use Omnitrix\Mail\Message;
use Omnitrix\Mail\Transport\ArrayTransport;
use Omnitrix\Notifications\DatabaseNotification;
use Omnitrix\Notifications\Notifiable;
use Omnitrix\Notifications\Notification;
use Omnitrix\Notifications\NotificationSender;
use Omnitrix\Tests\Integration\Database\ModelsAndMigrationsTest;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function dirname;

/**
 * @property int    $id
 * @property string $email
 */
final class Member extends Model
{
    use Notifiable;

    protected static ?string $table = 'users';

    /** @var list<string> */
    protected static array $fillable = ['name', 'email', 'password'];
}

class InvoicePaid extends Notification
{
    /** @param list<string> $channels */
    public function __construct(public int $invoice, public array $channels = ['mail', 'database']) {}

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): Message
    {
        return (new Message())->subject("Invoice {$this->invoice} paid")->text('Thank you.');
    }

    public function toArray(object $notifiable): array
    {
        return ['invoice' => $this->invoice];
    }
}

final class QueuedInvoicePaid extends InvoicePaid implements ShouldQueue {}

#[CoversClass(NotificationSender::class)]
#[CoversClass(DatabaseNotification::class)]
final class NotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ModelsAndMigrationsTest::createDatabase();
    }

    public function testMailAndDatabaseChannelsQueueingAndTheFake(): void
    {
        $config = ModelsAndMigrationsTest::config() + ['mail' => ['default' => 'array', 'from' => ['address' => 'billing@example.com']]];
        $app = Application::create(['server' => ['port' => 18670]] + $config);
        $app->withServices(static function (): void {
            (new Migrator(DB::manager(), dirname(__DIR__, 2) . '/Fixtures/migrations'))->fresh();
            (new Schema(DB::connection()))->create('notifications', static function (Blueprint $table): void {
                $table->string('id', 40)->primary();
                $table->string('type');
                $table->string('notifiable_type');
                $table->unsignedBigInteger('notifiable_id');
                $table->json('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
            $mailer = Facade::container()?->get(Mailer::class);
            self::assertInstanceOf(Mailer::class, $mailer);
            $transport = $mailer->transport();
            self::assertInstanceOf(ArrayTransport::class, $transport);

            $ann = Member::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
            $ann->notify(new InvoicePaid(42));
            self::assertCount(1, $transport->messages);
            self::assertSame('ann@example.com', $transport->messages[0]->getTo()[0]->email);
            self::assertSame('billing@example.com', $transport->messages[0]->getFrom()?->email);
            $stored = $ann->notifications()->get();
            self::assertCount(1, $stored);
            $first = $stored->first();
            self::assertInstanceOf(DatabaseNotification::class, $first);
            self::assertSame(['invoice' => 42], $first->data);
            self::assertSame(1, $ann->unreadNotifications()->count());
            $first->markAsRead();
            self::assertSame(0, $ann->unreadNotifications()->count());

            Notify::route('mail', 'ops@example.com')->notify(new InvoicePaid(7, ['mail']));
            self::assertSame('ops@example.com', $transport->messages[1]->getTo()[0]->email, 'anonymous recipients');

            // Queued: the model travels by key and is reloaded in the worker.
            $jobs = [];
            $sender = new NotificationSender($mailer, static function (string $job, array $payload) use (&$jobs): void {
                $jobs[] = $payload;
            });
            $sender->send([$ann], new QueuedInvoicePaid(43, ['database']));
            self::assertSame(1, DatabaseNotification::query()->count(), 'not sent during the request');
            $sender->handleQueued($jobs[0]);
            self::assertSame(2, DatabaseNotification::query()->count());
            $sender->send($ann, new QueuedInvoicePaid(44, ['database']));
            $ann->delete();
            $sender->handleQueued($jobs[1]);
            self::assertSame(2, DatabaseNotification::query()->count(), 'a deleted recipient is skipped');

            $fake = Notify::fake();
            $bob = Member::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x']);
            $bob->notify(new InvoicePaid(5));
            $fake->assertSentTo($bob, InvoicePaid::class, static fn(Notification $n): bool => $n instanceof InvoicePaid && $n->invoice === 5);
            $fake->assertNotSentTo($bob, QueuedInvoicePaid::class);
            self::assertCount(2, $transport->messages, 'nothing sent while faked');
        });
    }
}
