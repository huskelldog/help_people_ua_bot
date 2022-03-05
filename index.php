<?php

require_once __DIR__ . '/vendor/autoload.php';

use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\BotMan\Interfaces\CacheInterface;
use BotMan\BotMan\Interfaces\StorageInterface;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question;
use BotMan\Drivers\Telegram\TelegramDriver;
use Predis\Client;
use Predis\Collection\Iterator\Keyspace;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Illuminate\Support\Collection;

final class HelpUaConversation extends Conversation {

    const NEED_HELP = 'need_help';
    const PROVIDE_HELP = 'provide_help';

    const YES = 'Yes';
    const NO = 'No';

    const UA = 'ua';
    const RU = 'ru';
    const PO = 'po';
    const HU = 'hu';
    const SK = 'sk';
    const RO = 'ro';
    const EN = 'en';

    const languages = [
        HelpUaConversation::UA,
        HelpUaConversation::RU,
        HelpUaConversation::PO,
        HelpUaConversation::HU,
        HelpUaConversation::SK,
        HelpUaConversation::RO,
        HelpUaConversation::EN,
    ];

    public $language = HelpUaConversation::EN;
    public $location = 'Unknown';
    public $needs = [];

    function run (): void {
        $this->askForLanguage();
    }

    protected function askForLanguage (): HelpUaConversation {
        $this->say(t('Which language do you speak?', HelpUaConversation::UA));
        $this->say(t('Which language do you speak?', HelpUaConversation::RU));
        $this->say(t('Which language do you speak?', HelpUaConversation::PO));
        $this->say(t('Which language do you speak?', HelpUaConversation::HU));
        $this->say(t('Which language do you speak?', HelpUaConversation::SK));
        $this->say(t('Which language do you speak?', HelpUaConversation::RO));

        $question = Question::create(t('Which language do you speak?', HelpUaConversation::EN))
            ->callbackId('language')
            ->addButton(Button::create('український')->value(HelpUaConversation::UA))
            ->addButton(Button::create('русский')->value(HelpUaConversation::RU))
            ->addButton(Button::create('Polski')->value(HelpUaConversation::PO))
            ->addButton(Button::create('Magyar')->value(HelpUaConversation::HU))
            ->addButton(Button::create('Slovenský')->value(HelpUaConversation::SK))
            ->addButton(Button::create('Română')->value(HelpUaConversation::RO))
            ->addButton(Button::create('English')->value(HelpUaConversation::EN));

        return $this->ask($question, function (Answer $answer) {
            if ( ! $answer->isInteractiveMessageReply())
            {
                // TODO: Verify if this is even needed
                return $this;
            }

            if ( ! in_array($answer->getValue(), HelpUaConversation::languages))
            {
                $this->say(t('Sorry, again?', $this->language));

                return $this->askForLanguage();
            }

            $this->language = $answer->getValue();

            return $this->askForTypeOfInteraction();
        });
    }

    protected function askForTypeOfInteraction (): HelpUaConversation {
        $question = Question::create(t('Hi, do you need help or do you want to provide help?', $this->language))
            ->callbackId('interaction_type')
            ->addButton(Button::create(t('I need help', $this->language))->value(HelpUaConversation::NEED_HELP))
            ->addButton(Button::create(t('I want to provide help', $this->language))->value(HelpUaConversation::PROVIDE_HELP));

        $this->ask($question, function (Answer $answer) {
            if (!$answer->isInteractiveMessageReply()) {
                // TODO: Verify if this is even needed
                return $this;
            }

            switch ($answer->getValue())
            {
                case HelpUaConversation::NEED_HELP:
                    $this->say(t('Okay, you NEED help.', $this->language));

                    return $this->askForLocationOfHelp();
                case HelpUaConversation::PROVIDE_HELP:
                    $this->say(t('Okay, you want to PROVIDE help', $this->language));

                    return $this->askForNearbyLocations();
                default:
                    $this->say(t("Sorry, I didn't understand that, could you try again?", $this->language));

                    return $this->askForTypeOfInteraction();
            }
        });

        return $this;
    }

    protected function askForLocationOfHelp (): HelpUaConversation {
        $this->say(t('Where do you need help?', $this->language));
        $this->typeAndWaitsQuarterOfASecond();
        $this->say(t('For example Partyzanskoyi Slavy Park, Kyiv', $this->language));

        return $this->ask(t('or Polish border near Lviv', $this->language), function (Answer $answer) {
            $this->location = $answer->getText();
            $this->say(t('Ok, you need help at the following location', $this->language));
            $this->typeAndWaitsQuarterOfASecond();
            $this->say($this->location);

            return $this->askWhatIsNeeded(t('What do you need?', $this->language));
        });
    }

    protected function askForNearbyLocations (): HelpUaConversation {
        return $this->ask(t('Which of these locations is nearby for you?', $this->language), function (Answer $answer) {
            return $this;
        });
    }

    protected function askWhatIsNeeded (string $questionText): HelpUaConversation {
        return $this->ask($questionText, function (Answer $answer) {
            $this->needs[] = $answer->getText();

            $this->say(t('Here are the things you asked for so far:', $this->language));

            foreach ($this->needs as $need) {
                $this->say(" * " . $need);
            }

            $this->confirmIfThatAreAllTheThingsNeeded();
        });
    }

    protected function confirmIfThatAreAllTheThingsNeeded (): HelpUaConversation {
        $question = Question::create(t('Do you need to add anything else?', $this->language))
            ->callbackId('needs')
            ->addButton(Button::create(t('Yes', $this->language))->value(HelpUaConversation::YES))
            ->addButton(Button::create(t('No', $this->language))->value(HelpUaConversation::NO));

        return $this->ask($question, function (Answer $answer) {
            if ( ! $answer->isInteractiveMessageReply())
            {
                // TODO: Verify if this is even needed
                return $this;
            }

            switch ($answer->getValue())
            {
                case HelpUaConversation::YES:
                    return $this->askWhatIsNeeded(t('What else do you need?', $this->language));
                case HelpUaConversation::NO:
                    $this->say(t('Ok, this is the list of things you have asked for', $this->language));
                    $this->say(t('Stay safe, and take care!', $this->language));

                    return $this->say('🇺🇦 **Slava Ukraini!** 🇺🇦');
                default:
                    $this->say(t("Sorry, I didn't understand you. ", $this->language));

                    return $this->confirmIfThatAreAllTheThingsNeeded();
            }
        });
    }

    function typeAndWaitsQuarterOfASecond(): HelpUaConversation {
        $this->getBot()->getDriver()->types($this->getBot()->getMessage());
        usleep(250000);

        return $this;
    }
}

function telegram_webhook(ServerRequestInterface $request): string
{
    $config = [
        "telegram" => [
            "token" => getenv('WHAT_IS_THE_TOKEN_TO_CONNECT_TO_TELEGRAM'),
        ],
    ];
    DriverManager::loadDriver(TelegramDriver::class);
    $httpFoundationFactory = new HttpFoundationFactory();
    $cache = new PredisCache(
        getenv('WHAT_IS_THE_IP_TO_CONNECT_TO_REDIS'),
        getenv('WHAT_IS_THE_PORT_TO_CONNECT_TO_REDIS')
    );
    $storage = new PredisStorage(
        getenv('WHAT_IS_THE_IP_TO_CONNECT_TO_REDIS'),
        getenv('WHAT_IS_THE_PORT_TO_CONNECT_TO_REDIS')
    );
    $botman = BotManFactory::create(
        $config,
        $cache,
        $httpFoundationFactory->createRequest($request),
        $storage
    );

    $botman->hears('/start', function($bot) {
        $bot->startConversation(new HelpUaConversation);
    });

    $botman->listen();

    return 'Ok';
}

function translations (): array {
    return [
        'ua' => [
            'Which language do you speak?' => 'Якою мовою ти розмовляєш?',
        ],
        'ru' => [
            'Which language do you speak?' => 'На каком языке ты говоришь?',
        ],
        'po' => [
            'Which language do you speak?' => 'W jakim języku mówisz?',
        ],
        'hu' => [
            'Which language do you speak?' => 'Milyen nyelven beszélnek?',
        ],
        'sk' => [
            'Which language do you speak?' => 'Akým jazykom hovoríš?',
        ],
        'ro' => [
            'Which language do you speak?' => 'Ce limbă vorbiți?',
        ],
    ];
}

function t(string $source, string $language): string {
    $translations = translations();

    if (array_key_exists($language, $translations) && array_key_exists($source, $translations[$language])) {
        return $translations[$language][$source];
    }

    if ($language === HelpUaConversation::EN) {
        return $source;
    }

    return $source . '(Notify @huizendveld with translation in ' . $language . ')';
}

final class PredisCache implements CacheInterface {
    const KEY_PREFIX = 'botman:cache:';

    /**
     * @type \Predis\Client
     */
    private $redis;
    private $host;
    private $port;
    private $auth;

    public function __construct($host = '127.0.0.1', $port = 6379, $auth = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->auth = $auth;
        $this->connect();
    }

    public function has($key)
    {
        $check = $this->redis->exists($this->decorateKey($key));

        if (is_bool($check)) {
            return $check;
        }

        return $check > 0;
    }

    public function get($key, $default = null)
    {
        return unserialize($this->redis->get($this->decorateKey($key))) ?: $default;
    }

    public function pull($key, $default = null)
    {
        $redisKey = $this->decorateKey($key);
        $r = $this->redis->executeRaw(['GETDEL', $redisKey]);

        return unserialize($r) ?: $default;
    }

    public function put($key, $value, $minutes)
    {
        if ($minutes instanceof \Datetime) {
            $seconds = $minutes->getTimestamp() - time();
        } else {
            $seconds = $minutes * 60;
        }
        $this->redis->setex($this->decorateKey($key), $seconds, serialize($value));
    }

    private function decorateKey($key)
    {
        return self::KEY_PREFIX.$key;
    }

    private function connect()
    {
        $this->redis = new Client(
            [
                'scheme' => 'tcp',
                'host'   => $this->host,
                'port'   => $this->port,
            ]
        );
        $this->redis->connect();

        if ($this->auth !== null) {
            $this->redis->auth($this->auth);
        }
    }

    public function __wakeup()
    {
        $this->connect();
    }
}

final class PredisStorage implements StorageInterface
{
    const KEY_PREFIX = 'botman:storage:';

    /**
     * @type \Predis\Client
     */
    private $redis;

    private $host;
    private $port;
    private $auth;

    public function __construct($host = '127.0.0.1', $port = 6379, $auth = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->auth = $auth;
        $this->connect();
    }

    /**
     * Save an item in the storage with a specific key and data.
     *
     * @param  array $data
     * @param  string $key
     */
    public function save(array $data, $key)
    {
        $this->redis->set($this->decorateKey($key), serialize($data));
    }

    /**
     * Retrieve an item from the storage by key.
     *
     * @param  string $key
     * @return Collection
     */
    public function get($key)
    {
        $value = unserialize($this->redis->get($this->decorateKey($key)));

        return $value ? Collection::make($value) : new Collection();
    }

    /**
     * Delete a stored item by its key.
     *
     * @param  string $key
     */
    public function delete($key)
    {
        $this->redis->del($this->decorateKey($key));
    }

    /**
     * Return all stored entries.
     *
     * @return array
     */
    public function all()
    {
        $entries = [];

        foreach (new Keyspace($this->redis, self::KEY_PREFIX.'*') as $key) {
            $entries[substr($key, strlen(self::KEY_PREFIX))] = Collection::make(serialize($this->redis->get($key)));
        }

        return $entries;
    }

    /**
     * Namespace botman keys in redis.
     *
     * @param $key
     * @return string
     */
    private function decorateKey($key)
    {
        return self::KEY_PREFIX.$key;
    }

    private function connect()
    {
        $this->redis = new Client(
            [
                'scheme' => 'tcp',
                'host'   => $this->host,
                'port'   => $this->port,
            ]
        );
        $this->redis->connect();

        if ($this->auth !== null) {
            $this->redis->auth($this->auth);
        }
    }

    public function __wakeup()
    {
        $this->connect();
    }
}

ini_set('error_reporting', E_ERROR);
register_shutdown_function("fatal_handler");
function fatal_handler() {
    $error = error_get_last();
    $message = json_encode($error);

    log_message($message);
}

function log_message ($message): void {
    $log = fopen('php://stderr', 'wb');
    fwrite($log, $message . PHP_EOL);
    fclose($log);
}

