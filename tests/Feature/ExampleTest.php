<?php

test('the application returns a successful response', function () {
    config()->set('session.driver', 'array');

    $response = $this->get('/');

    $response->assertSuccessful();
});
