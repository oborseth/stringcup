<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        // The stock CodeIgniter welcome page used to live here, which meant the
        // first thing any agent or human saw at the root said nothing about
        // this service and linked to none of its docs.
        return view('home');
    }
}
