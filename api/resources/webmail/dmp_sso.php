<?php

/**
 * One-click sign-in from the panel: ?_task=login&_sso=<token>. The token is exchanged
 * once, server to server, for a Dovecot master-user login to that one mailbox.
 */
class dmp_sso extends rcube_plugin
{
    public $task = 'login|mail';

    public function init()
    {
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
        $this->add_hook('user_create', [$this, 'user_create']);
    }

    public function startup($args)
    {
        if ($args['task'] === 'login' && ! empty($_GET['_sso'])) {
            $args['action'] = 'login';
        }

        return $args;
    }

    public function authenticate($args)
    {
        $token = rcube_utils::get_input_string('_sso', rcube_utils::INPUT_GET);
        if ($token === '' || ! preg_match('/^[A-Za-z0-9]{40,80}$/', $token)) {
            return $args;
        }
        $config = rcmail::get_instance()->config;
        $context = stream_context_create(['http' => ['timeout' => 5, 'header' => 'Authorization: Bearer '.$config->get('dmp_sso_secret')."\r\nAccept: application/json\r\n"]]);
        $body = @file_get_contents($config->get('dmp_sso_url').'/'.$token, false, $context);
        $data = $body ? json_decode($body, true) : null;
        if (empty($data['data']['user'])) {
            $args['abort'] = true;
            $args['error'] = 'The sign-in link has expired. Open webmail from the panel again.';

            return $args;
        }
        $args['user'] = $data['data']['user'];
        $args['pass'] = $data['data']['password'];
        $args['cookiecheck'] = false;
        $args['valid'] = true;

        return $args;
    }

    public function user_create($args)
    {
        $args['user_email'] = explode('*', $args['user'])[0];

        return $args;
    }
}
