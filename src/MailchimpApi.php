<?php

namespace Josieboy\Mailchimp;

use Josieboy\Mailchimp\Exception\MailchimpBadRequestException;
use Josieboy\Mailchimp\Exception\MailchimpException;
use Josieboy\Mailchimp\Exception\MailchimpInternalErrorException;
use Requests;
use Requests_Auth_Basic;
use Requests_Response;

class MailchimpApi
{
    protected $apikey;
    protected $baseurl = 'https://<dc>.api.mailchimp.com/3.0';
    protected $responseCode;

    public function __construct(string $apikey)
    {
        $this->apikey = $apikey;
        $exploded = explode('-', $apikey);
        $this->baseurl = str_replace('<dc>', array_pop($exploded), $this->baseurl);
    }

    // API calls --------------------------------------------------------------

    public function getLists(array $params = []): array
    {
        return $this->call('get', '/lists', $params);
    }

    public function getList(string $listId): array
    {
        return $this->call('get', '/lists/' . $listId);
    }

    public function getMember(string $listId, string $memberId): array
    {
        return $this->call('get', "/lists/{$listId}/members/{$memberId}");
    }

    public function newList(string $listname, array $contact)
    {
        $data = [
            'name' => $listname,
            'contact' => $contact,
            'email_type_option' => true,
            'permission_reminder' => 'You signed up from Memberbase Api',
            'campaign_defaults' => [
                'from_name' => $contact['FNAME'] . " " . $contact['LNAME'],
                'from_email' => $contact['EMAIL'],
                'subject' => "Default Subject Memberbase",
                'language' => "en",
            ]
        ];
        $this->call('post', '/lists', $data);
    }

    public function addUpdate(string $listId, string $email, array $tags, array $merge, bool $confirm)
    {
        $email = strtolower($email);
        $memberId = md5($email);
        $data = [
            'email_address' => $email,
            'status_if_new' => $confirm ? 'pending' : 'subscribed',
            'status'        => $confirm ? 'pending' : 'subscribed',
            'tags' => $tags
        ];
        // Empty array doesn't work
        if ($merge) {
            $data['merge_fields'] = $merge;
        }
        $this->call('put', "/lists/{$listId}/members/{$memberId}", $data);
    }

    public function addMemberTag(string $listId, string $email, array $tags)
    {
        $email = strtolower($email);
        $memberId = md5($email);
        $data = [
            'tags' => [
                $tags
            ]
        ];

        $this->call('post', "/lists/{$listId}/members/{$memberId}/tags", $data);
    }

    public function addUpdateMember(string $listId, Member $member)
    {
        $this->call('put', "/lists/{$listId}/members/{$member->hash()}", $member->parameters());
    }

    public function unsubscribe(string $listId, string $email)
    {
        $memberId = md5(strtolower($email));
        $this->call('put', "/lists/{$listId}/members/{$memberId}", ['email_address' => $email, 'status_if_new' => 'unsubscribed', 'status' => 'unsubscribed']);
    }

    // HTTP -------------------------------------------------------------------

    public function call(string $method, string $endpoint, array $data = []): array
    {
        $method = strtolower($method);
        if (!in_array($method, ['get', 'put', 'post', 'delete', 'patch'])) {
            throw new MailchimpException('Invalid API call method: ' . $method);
        }
        if (in_array($method, ['get', 'delete'])) {
            $url = $this->baseurl . $endpoint;
            $url .= $data ? '?' . http_build_query($data) : '';
            $response = Requests::$method($url, $this->headers(), $this->options());
        } else {
            $response = Requests::$method(
                $this->baseurl . $endpoint,
                $this->headers(),
                json_encode($data),
                $this->options()
            );
        }
        $this->responseCode = intval($response->status_code);
        if ($this->responseCode >= 400) {
            $this->apiError($response);
        }
        return json_decode($response->body, true) ?? [];
    }

    protected function options(): array
    {
        return [
            'auth' => new Requests_Auth_Basic(['mcuser', $this->apikey]),
        ];
    }

    protected function headers(): array
    {
        return [];
    }

    protected function apiError(Requests_Response $response)
    {
        $info = var_export(json_decode($response->body, true), true);
        $message = "Mailchimp API error (" . $response->status_code . "): " . $info;
        if ($this->responseCode <= 499) {
            throw new MailchimpBadRequestException($message, $this->responseCode, null, $response->body);
        }
        throw new MailchimpInternalErrorException($message, $this->responseCode);
    }

    public function responseCode(): int
    {
        return $this->responseCode;
    }

    public function responseCodeNotFound(): bool
    {
        return $this->responseCode == 404;
    }
}
