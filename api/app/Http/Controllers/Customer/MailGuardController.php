<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Admin\MailGuardController as AdminGuard;
use Illuminate\Http\Request;

/** MCL for customers: their own holds, spam settings and delivery log; releasing and limits stay with administrators. */
class MailGuardController extends AdminGuard
{
    public function search(Request $request)
    {
        $request->request->remove('mail_server_id');
        $request->query->remove('mail_server_id');

        return parent::search($request);
    }
}
