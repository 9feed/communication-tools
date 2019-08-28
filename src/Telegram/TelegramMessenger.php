<?php
/**
 * Created by PhpStorm.
 * User: he110
 * Date: 10/08/2019
 * Time: 02:26
 */

namespace He110\CommunicationTools\Telegram;


use He110\CommunicationTools\Exceptions\AccessTokenException;
use He110\CommunicationTools\Exceptions\AttachmentNotFoundException;
use He110\CommunicationTools\Exceptions\TargetUserException;
use He110\CommunicationTools\MessengerInterface;
use He110\CommunicationTools\MessengerScreen;
use He110\CommunicationTools\MessengerWithTokenInterface;
use He110\CommunicationTools\ScreenItems\Button;
use He110\CommunicationTools\ScreenItems\File;
use He110\CommunicationTools\ScreenItems\ScreenItemInterface;
use He110\CommunicationTools\ScreenItems\Voice;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Message;

class TelegramMessenger extends TelegramMessengerEvents implements MessengerInterface, MessengerWithTokenInterface
{
    /** @var BotApi */
    private $client = null;

    /** @var string|null */
    private $accessToken;

    /** @var string */
    protected $userId;

    /**
     * {@inheritdoc}
     */
    public function setTargetUser(?string $userId)
    {
        $this->userId = $userId;
    }

    /**
     * {@inheritdoc}
     */
    public function getTargetUser(): ?string
    {
        return $this->userId;
    }

    /**
     * {@inheritdoc}
     *
     * @param string $text
     * @param array $buttons
     * @return bool
     * @throws AccessTokenException
     * @throws TargetUserException
     */
    public function sendMessage(string $text, array $buttons = []): bool
    {
        $this->checkRequirements();

        try {
            $keyboard = $this->generateButtonMarkup($buttons);
            $result = $this->client->sendMessage(
                $this->getTargetUser(),
                $text,
                null,
                false,
                null,
                $keyboard
            );

            return $this->checkRequestResult($result);
        } catch (\Exception $exception) {
            return false;
        }

    }

    /**
     * {@inheritdoc}
     *
     * @param string $fileUrl
     * @param string|null $description
     * @param array $buttons
     * @return bool
     * @throws AccessTokenException
     * @throws TargetUserException
     */
    public function sendImage(string $fileUrl, string $description = null, array $buttons = []): bool
    {
        return $this->workWithAttachment("image", $fileUrl, $description, $buttons);
    }

    /**
     * {@inheritdoc}
     *
     * @param string $fileUrl
     * @param string|null $description
     * @param array $buttons
     * @return bool
     * @throws AccessTokenException
     * @throws TargetUserException
     */
    public function sendDocument(string $fileUrl, string $description = null, array $buttons = []): bool
    {
        return $this->workWithAttachment("document", $fileUrl, $description, $buttons);
    }

    /**
     * @param string $type
     * @param $fileUrl
     * @param $description
     * @param $buttons
     * @return bool
     * @throws AccessTokenException
     * @throws TargetUserException
     */
    private function workWithAttachment(string $type, $fileUrl, $description, $buttons): bool
    {
        $method = $type == "image" ? "sendPhoto" : "sendDocument";
        $this->checkRequirements();
        try {
            $keyboard = $this->generateButtonMarkup($buttons);
            $result = $this->client->{$method}(
                $this->getTargetUser(),
                $fileUrl,
                $description,
                null,
                $keyboard
            );
            return $this->checkRequestResult($result);
        } catch (\Exception $exception) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param string $pathToFile
     * @return bool
     * @throws AccessTokenException
     * @throws AttachmentNotFoundException
     * @throws TargetUserException
     */
    public function sendVoice(string $pathToFile): bool
    {
        $this->checkRequirements();
        $document = $this->prepareFile($pathToFile);
        try {
            return $this->checkRequestResult($this->client->sendVoice($this->getTargetUser(), $document));
        } catch (\Exception $exception) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function sendScreen(MessengerScreen $screen): bool
    {
        $buttons = array();
        $current = null;
        $result = true;

        foreach ($screen->fixItemsOrder() as $index => $item) {
            if ($item instanceof Button)
                $buttons[] = $item;
            else {
                list($current, $result, $buttons) = $this->sendScreenHelper($current, $item, $result, $buttons);
            }
        }
        $this->workWithScreenItem($current, $buttons);
        return $result;
    }

    /**
     * @param ScreenItemInterface $item
     * @param array $buttons
     * @return bool
     * @throws AccessTokenException
     * @throws AttachmentNotFoundException
     * @throws TargetUserException
     */
    private function workWithScreenItem(ScreenItemInterface $item, array $buttons = []): bool
    {
        $class = get_class($item);
        switch ($class) {
            case \He110\CommunicationTools\ScreenItems\Message::class:
                return $this->sendMessage($item->getText(), $buttons);
            case Voice::class:
                return $this->sendVoice($item->getPath());
            default:
                $method = $item->getType() == File::FILE_TYPE_IMAGE ? "sendImage" : "sendDocument";
                return $this->{$method}($item->getPath(), $item->getDescription());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setAccessToken(?string $token)
    {
        $this->accessToken = $token;
        $this->client = new BotApi($token);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    /**
     * @throws AccessTokenException
     * @throws TargetUserException
     */
    private function checkRequirements()
    {
        if (is_null($this->client) || !$this->getAccessToken()) {
            throw new AccessTokenException("Telegram access token required");
        }

        if (empty($this->getTargetUser())) {
            throw new TargetUserException("Target Client ID required");
        }

    }

    /**
     * @param string $pathToFile
     * @return \CURLFile
     * @throws AttachmentNotFoundException
     */
    private function prepareFile(string $pathToFile): \CURLFile
    {
        if (!file_exists($pathToFile))
            throw new AttachmentNotFoundException("Attachment not found");
        return new \CURLFile($pathToFile);
    }

    /**
     * @param Message $message
     * @return bool
     */
    private function checkRequestResult(Message $message): bool
    {
        return method_exists($message, "getMessageId") && $message->getMessageId();
    }

    /**
     * @param Button[] $buttons
     * @return null|\TelegramBot\Api\Types\Inline\InlineKeyboardMarkup
     */
    private function generateButtonMarkup(array $buttons)
    {
        if ($buttons && current($buttons) instanceof Button) {
            $content = [];
            foreach ($buttons as $button) {
                $content[] = $this->buttonToArray($button);
            }
            return new \TelegramBot\Api\Types\Inline\InlineKeyboardMarkup($content);
        }
        return null;
    }

    /**
     * @param Button $button
     * @return array
     */
    private function buttonToArray(Button $button): array
    {
        if ($button->getType() == Button::BUTTON_TYPE_URL)
            return array(
                array(
                    "text" => $button->getLabel(),
                    "url" => $button->getContent()
                )
            );
        elseif ($button->getType() == Button::BUTTON_TYPE_CALLBACK) {
            return array(
                array(
                    "text" => $button->getLabel(),
                    "callback_data" => "clb=".$button->getContent()
                )
            );
        }
        else {
            return array(
                array(
                    "text" => $button->getLabel(),
                    "callback_data" => "text=" . $button->getLabel()
                )
            );
        }
    }

    /**
     * @param $current
     * @param $item
     * @param $result
     * @param $buttons
     * @return array
     * @throws AccessTokenException
     * @throws AttachmentNotFoundException
     * @throws TargetUserException
     */
    private function sendScreenHelper($current, $item, $result, $buttons): array
    {
        if ($current === null) {
            $current = $item;
        } elseif ($current !== $item && $current !== null) {
            $result = $result && $this->workWithScreenItem($current, $buttons);
            if (!($current instanceof Voice)) {
                $buttons = [];
            }
            $current = $item;
        }
        return array($current, $result, $buttons);
    }
}