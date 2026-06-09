<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Api\OpenApiGenerator;
use Cake\Http\Response;
use Cake\Routing\Router;

/**
 * GET /api/v1/openapi.json — maschinenlesbare OpenAPI-3.1-Spezifikation der API
 * (Core-Endpunkte + Modul-Routen), generiert aus dem tatsächlichen Bestand (P07).
 */
class OpenApiController extends ApiController
{
    public function index(): Response
    {
        $base = rtrim(Router::url('/', true), '/');

        return $this->json((new OpenApiGenerator())->generate($base));
    }
}
