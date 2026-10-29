<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_docs_and_local_spec_describe_the_public_api(): void
    {
        $this->get('/docs')->assertOk()
            ->assertSee('Swagger UI')
            ->assertSee('/vendor/swagger-ui/swagger-ui-bundle.js')
            ->assertSee('/vendor/swagger-ui/swagger-initializer.js');

        $response = $this->getJson('/openapi.json')->assertOk()
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('servers.0.url', '/')
            ->assertJsonPath('paths./postback.get.parameters.2.name', 'amount')
            ->assertJsonPath('paths./postback.get.parameters.2.schema.type', 'string')
            ->assertJsonPath('components.schemas.Postback.properties.attempts.maximum', 3)
            ->assertJsonPath('components.securitySchemes.inspectionBearer.scheme', 'bearer');

        $spec = $response->json();
        self::assertSame(['/postback', '/events', '/up'], array_keys($spec['paths']));
        self::assertSame(['200', '422', '503'], array_map('strval', array_keys($spec['paths']['/postback']['get']['responses'])));
        self::assertSame('Event received', $spec['components']['schemas']['Accepted']['properties']['message']['enum'][0]);
        self::assertSame('Event already received', $spec['components']['schemas']['Duplicate']['properties']['message']['enum'][0]);
        self::assertSame([[], ['inspectionBearer' => []]], $spec['paths']['/events']['get']['security']);
    }
}
