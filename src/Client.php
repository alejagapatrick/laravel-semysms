<?php


namespace Allanvb\LaravelSemysms;

use Allanvb\LaravelSemysms\SemySms;
use Allanvb\LaravelSemysms\Rules\IntervalRule;
use Illuminate\Support\Facades\Validator;

class Client extends SemySms
{
    /**
     * @param array $data
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Support\Collection|mixed
     * @throws Exceptions\RequestException
     * @throws Exceptions\SmsNotSentException
     */
    public function sendOne(array $data)
    {
        $url = self::SEND_URL;

        $validator = Validator::make($data, [
            'to' => 'required|string|max:30|regex:/^\+\d+$/',
            'text' => 'required|max:255',
        ]);

        $validator->sometimes('device_id', 'digits_between:1,10', function ($input) {
            return is_numeric($input->device_id);
        });

        $validator->sometimes('device_id', 'in:active', function ($input) {
            return !is_numeric($input->device_id);
        });

        if ($validator->fails()) {
            return back()->withErrors($validator->errors());
        }

        $postData = [
            'token' => $this->token
        ];

        $postData['device'] = $data['device_id'] ?? $this->device_id;
        $postData['phone'] = $data['to'];
        $postData['msg'] = $data['text'];

        $request = $this->performRequest($postData, $url);

        $this->validateRequest($request);

        $response = collect($data);
        $response->prepend(json_decode($request['body'])->id, 'message_id');

        $this->dispatch('semy-sms.sent', $response);

        return $response;
    }

    /**
     * @param array $data
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Support\Collection|mixed
     * @throws Exceptions\RequestException
     * @throws Exceptions\SmsNotSentException
     */
    public function sendMultiple(array $data)
    {
        $url = self::SEND_MULTIPLE_URL;

        $validator = Validator::make($data, [
            'to' => 'required|array',
            'to.*' => 'max:30|regex:/^\+\d+$/',
            'text' => 'required|max:255'
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator->errors());
        }

        $postData = [
            'token' => $this->token
        ];

        foreach ($data['to'] as $phone) {

            $postData['data'][] = [
                'device' => $this->device_id,
                'phone' => $phone,
                'msg' => $data['text']
            ];
        }

        $request = $this->performRequest($postData, $url, true);

        $this->validateRequest($request);
        $body = json_decode($request['body'], true);

        $response = collect($body['data'] ?? [])->map(function ($data, $key) use ($postData) {
            return [
                'message_id' => (int)$data['id'],
                'device_id' => (int)$postData['data'][$key]['device'],
                'to' => (string)$postData['data'][$key]['phone'],
                'text' => $postData['data'][$key]['msg']
            ];
        });

        $this->dispatch('semy-sms.sent-multiple', $response);


        return $response;
    }

    /**
     * @return $this
     */
    public function multiple()
    {
        $this->recipients['token'] = $this->token;
        $this->recipients['data'] = [];

        return $this;
    }

    /**
     * @param array $data
     * @return $this
     */
    public function addRecipient(array $data)
    {
        $validator = Validator::make($data, [
            'to' => 'required|string|max:30|regex:/^\+\d+$/',
            'text' => 'required|max:255',
            'device_id' => 'numeric|digits_between:1,10',
            'my_id' => 'max:50'
        ]);

        if ($validator->fails()) {
            return $validator->errors();
        }

        $recipient = [
            'phone' => $data['to'],
            'msg' => $data['text'],
        ];

        $recipient['device'] = $data['device_id'] ?? $this->device_id;

        if (isset($data['my_id'])) {
            $recipient['my_id'] = $data['my_id'];
        }

        array_push($this->recipients['data'], $recipient);

        return $this;
    }

    /**
     * @return \Illuminate\Support\Collection
     * @throws Exceptions\RequestException
     * @throws Exceptions\SmsNotSentException
     */
    public function send()
    {
        $url = self::SEND_MULTIPLE_URL;

        $request = $this->performRequest($this->recipients, $url, true);

        $this->validateRequest($request);

        $body = json_decode($request['body'], true);

        $response = collect($body['data'] ?? [])->map(function ($data, $key) {
            $message = [
                'message_id' => (int)$data['id'],
                'device_id' => (int)$this->recipients['data'][$key]['device'],
                'to' => (string)$this->recipients['data'][$key]['phone'],
                'text' => $this->recipients['data'][$key]['msg']
            ];

            if (isset($this->recipients['data'][$key]['my_id'])) {
                $message['my_id'] = $data['my_id'];
            }

            return $message;
        });

        $this->dispatch('semy-sms.sent-multiple', $response);

        return $response;
    }

    /**
     * @param array $data
     * @return \Illuminate\Support\Collection|mixed
     * @throws Exceptions\RequestException
     * @throws Exceptions\SmsNotSentException
     */
    public function ussd(array $data)
    {
        $url = self::SEND_URL;

        $validator = Validator::make($data, [
            'to' => 'required|string|max:10|regex:/^\\*[0-9*]+#$/',
            'device_id' => 'numeric|digits_between:1,10'
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator->errors());
        }

        $postData = [
            'token' => $this->token
        ];

        $postData['device'] = $data['device_id'] ?? $this->device_id;
        $postData['phone'] = $data['to'];
        $postData['msg'] = '[ussd]';

        $request = $this->performRequest($postData, $url);

        $this->validateRequest($request);

        $response = collect($data);
        $response->prepend(json_decode($request['body'])->id, 'message_id');

        $this->dispatch('semy-sms.sent', $response);

        return $response;
    }

    /**
     * @param array|null $data
     * @return \Illuminate\Support\Collection|\Illuminate\Support\MessageBag|mixed
     * @throws Exceptions\InvalidIntervalException
     * @throws Exceptions\RequestException
     * @throws Exceptions\SmsNotSentException
     */
    public function getOutbox(array $data = null)
    {
        $url = self::GET_OUTBOX_LIST_URL;

        $response = $this->createListRequest($data, $url);

        return $response;
    }

    /**
     * @param array|null $data
     * @return \Illuminate\Support\Collection|\Illuminate\Support\MessageBag|mixed
     * @throws Exceptions\InvalidIntervalException
     * @throws Exceptions\RequestException
     * @throws Exceptions\SmsNotSentException
     */
    public function deleteOutbox(array $data = null)
    {
        $url = self::DELETE_OUTBOX_LIST_URL;

        $response = $this->createListRequest($data, $url);

        return $response;
    }

    /**
     * @param array|null $data
     * @return \Illuminate\Support\Collection|\Illuminate\Support\MessageBag|mixed
     * @throws Exceptions\InvalidIntervalException
     * @throws Exceptions\RequestException
     * @throws Exceptions\SmsNotSentException
     */
    public function getInbox(array $data = null)
    {
        $url = self::GET_INBOX_LIST_URL;

        $response = $this->createListRequest($data, $url);

        return $response;
    }

    /**
     * @param array|null $data
     * @return \Illuminate\Support\Collection|\Illuminate\Support\MessageBag|mixed
     * @throws Exceptions\InvalidIntervalException
     * @throws Exceptions\RequestException
     * @throws Exceptions\SmsNotSentException
     */
    public function deleteInbox(array $data = null)
    {
        $url = self::DELETE_INBOX_LIST_URL;

        $response = $this->createListRequest($data, $url);

        return $response;
    }

    /**
     * @param array|null $data
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Support\Collection|mixed
     * @throws Exceptions\RequestException
     * @throws Exceptions\SmsNotSentException
     */
    public function getDevices(array $data = null)
    {
        $url = self::GET_DEVICES_LIST_URL;

        if (isset($data)) {
            $validator = Validator::make($data, [
                'status' => 'in:active,archived',
                'list_id' => 'array'
            ]);

            if ($validator->fails()) {
                return back()->withErrors($validator->errors());
            }
        }

        $postData = [
            'token' => $this->token
        ];

        if (isset($data['status'])) {
            switch ($data['status']) {
                case 'active':
                    $postData['is_arhive'] = 0;
                    break;
                case 'archived':
                    $postData['is_arhive'] = 1;
                    break;
            }
        }

        if (isset($data['list_id'])) {
            $postData['list_id'] = implode(',', $data['list_id']);
        }

        $request = $this->performRequest($postData, $url);

        $this->validateRequest($request);

        $response = collect(json_decode($request['body'], true)['data']);

        return $response;
    }

    /**
     * @param array|null $data
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Support\Collection|mixed
     * @throws Exceptions\RequestException
     * @throws Exceptions\SmsNotSentException
     */
    public function cancelSMS(array $data = null)
    {
        $url = self::CANCEL_SMS_URL;

        if (isset($data)) {
            $validator = Validator::make($data, [
                'device_id' => 'numeric|digits_between:1,10',
                'sms_id' => 'numeric',
            ]);

            if ($validator->fails()) {
                return back()->withErrors($validator->errors());
            }
        }

        $postData = [
            'token' => $this->token,
        ];

        if (isset($data['device_id'])) {
            $postData['device'] = $data['device_id'];
            $postData['id_sms'] = 1;
        } else {
            $postData['device'] = $this->device_id;
            $postData['id_sms'] = 1;
        }

        if (isset($data['sms_id'])) {
            $postData['id_sms'] = $data['sms_id'];
            unset($postData['device']);
        }

        $request = $this->performRequest($postData, $url);

        $this->validateRequest($request);

        unset($postData['token']);

        $response = collect($postData);

        return $response;
    }

}
