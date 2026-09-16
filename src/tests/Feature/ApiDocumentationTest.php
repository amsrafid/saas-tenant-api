<?php

it('serves the OpenAPI specification', function () {
    $this->getJson('docs/api.json')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('servers.0.url', url('api/v1'));
});

it('serves Swagger UI pointed at the specification', function () {
    $this->get('api/documentation')
        ->assertOk()
        ->assertSee('swagger-ui-bundle.js', escape: false)
        ->assertSee("url: '/docs/api.json'", escape: false);
});
