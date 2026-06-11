<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Api\OpenApiGenerator;
use Cake\Http\Response;
use Cake\Routing\Router;

/**
 * GET /api/v1/openapi.json — machine-readable OpenAPI 3.1 specification of the API
 * (core endpoints + module routes), generated from the actual live state (P07).
 */
class OpenApiController extends ApiController
{
    public function index(): Response
    {
        $base = rtrim(Router::url('/', true), '/');

        return $this->json((new OpenApiGenerator())->generate($base));
    }
}
