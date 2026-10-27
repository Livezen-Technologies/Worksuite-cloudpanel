<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;

class TemplateController extends Controller
{
    public function index()
    {
        return $this->data(collect(config('platform.app_templates'))->map(fn ($t, $key) => ['id' => $key] + $t)->values());
    }
}
