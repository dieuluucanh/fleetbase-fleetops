<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Geocoder\Laravel\Facades\Geocoder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GeocoderController extends Controller
{
    /**
     * Reverse geocodes the given coordinates and returns the results as JSON.
     *
     * @param Request $request the HTTP request object
     *
     * @return \Illuminate\Http\Response the JSON response with the geocoded results
     */
    public function reverse(Request $request)
    {
        $query  = $request->or(['coordinates', 'query']);
        $single = $request->boolean('single');

        // Resolve strictly: getPointFromCoordinates() is typed `: Point` and
        // falls back to Point(0, 0) for unusable input, which would silently
        // reverse-geocode Null Island instead of reporting the bad request
        /** @var \Fleetbase\LaravelMysqlSpatial\Types\Point|null $coordinates */
        $coordinates = Utils::getPointFromCoordinatesStrict($query);

        // if not a valid point error
        if (!$coordinates instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point) {
            return response()->error('Invalid coordinates provided.');
        }

        // get results
        $results = $this->reverseGeocode(
            $coordinates->getLat(),
            $coordinates->getLng()
        );

        if ($results->count()) {
            if ($single) {
                $googleAddress = $results->first();

                return response()->json(
                    $this->placeFromGoogleAddress($googleAddress)
                );
            }

            return response()->json(
                $results
                    ->map(
                        function ($googleAddress) {
                            return $this->placeFromGoogleAddress(
                                $googleAddress
                            );
                        }
                    )
                    ->values()
                    ->toArray()
            );
        }

        return response()->json([]);
    }

    /**
     * Geocodes the given query and returns the results as JSON.
     *
     * @param Request $request the HTTP request object
     *
     * @return \Illuminate\Http\Response the JSON response with the geocoded results
     */
    public function geocode(Request $request)
    {
        $query  = $request->input('query');
        $single = $request->boolean('single');

        if (is_array($query)) {
            return $this->reverse($request);
        }

        // lookup
        $results = $this->forwardGeocode($query);

        if ($results->count()) {
            if ($single) {
                $googleAddress = $results->first();

                return response()->json(
                    $this->placeFromGoogleAddress($googleAddress)
                );
            }

            return response()->json(
                $results
                    ->map(
                        function ($googleAddress) {
                            return $this->placeFromGoogleAddress(
                                $googleAddress
                            );
                        }
                    )
                    ->values()
                    ->toArray()
            );
        }

        return response()->json([]);
    }

    /**
     * Geocode an address using OpenStreetMap Nominatim.
     *
     * This endpoint intentionally does not use Fleetbase's default
     * geocoder/query implementation.
     *
     * GET /geocoder/query-oss?query=Singapore&single=true
     */
    public function geocodeOss(Request $request)
    {
        $query = $request->input('query');

        $single = filter_var(
            $request->input('single', false),
            FILTER_VALIDATE_BOOLEAN
        );

        if (blank($query)) {
            return response()->json(
                $single ? null : []
            );
        }

        try {
            /**
             * Support coordinates too:
             *
             * query[]=lat&query[]=lng
             *
             * This makes query-oss reasonably compatible with the
             * existing Fleetbase geocoder behaviour.
             */
            if (is_array($query)) {
                return $this->reverseGeocodeOss(
                    $query,
                    $single,
                    $request
                );
            }

            $limit = $single
                ? 1
                : min(
                    max(
                        (int) $request->input('limit', 5),
                        1
                    ),
                    10
                );

            /**
             * Prefer environment variable when available.
             *
             * If NOMINATIM_LANGUAGE is missing or empty:
             * fallback => vi,en
             */
            $language = env('NOMINATIM_LANGUAGE')
                ?: 'vi,en';

            /**
             * Prefer environment variable when available.
             *
             * If NOMINATIM_EMAIL is missing or empty:
             * fallback => it.service@kiwifood.net
             */
            $email = env('NOMINATIM_EMAIL')
                ?: 'it.service@kiwifood.net';

            $params = [
                'q'              => trim((string) $query),
                'format'         => 'jsonv2',
                'addressdetails' => 1,
                'limit'          => $limit,

                'accept-language' => $request->input(
                    'language',
                    $language
                ),

                'email' => $email,
            ];

            $results = $this->nominatimRequest(
                'search',
                $params
            );

            if (!is_array($results)) {
                return response()->json(
                    $single ? null : []
                );
            }

            $places = collect($results)
                ->map(
                    fn ($result) =>
                        $this->normalizeNominatimPlace(
                            $result
                        )
                )
                ->filter()
                ->values()
                ->all();

            if ($single) {
                return response()->json(
                    $places[0] ?? null
                );
            }

            return response()->json($places);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' =>
                    'OpenStreetMap geocoding lookup failed.',

                'message' =>
                    $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Reverse geocode coordinates via Nominatim.
     */
    private function reverseGeocodeOss(
        array $coordinates,
        bool $single,
        Request $request
    ) {
        /**
         * Fleetbase Utils already knows how to normalize coordinate input.
         */
        $point = Utils::getPointFromCoordinates(
            $coordinates
        );

        if (!$point) {
            return response()->json([
                'error' =>
                    'Invalid coordinates provided.',
            ], 422);
        }

        /**
         * Prefer environment variable when available.
         *
         * Fallback language:
         * vi,en
         */
        $language = env('NOMINATIM_LANGUAGE')
            ?: 'vi,en';

        /**
         * Prefer environment variable when available.
         *
         * Fallback email:
         * it.service@kiwifood.net
         */
        $email = env('NOMINATIM_EMAIL')
            ?: 'it.service@kiwifood.net';

        $params = [
            'lat'            => $point->getLat(),
            'lon'            => $point->getLng(),
            'format'         => 'jsonv2',
            'addressdetails' => 1,

            'accept-language' => $request->input(
                'language',
                $language
            ),

            'email' => $email,
        ];

        $result = $this->nominatimRequest(
            'reverse',
            $params
        );

        if (
            !is_array($result) ||
            !isset($result['lat']) ||
            !isset($result['lon'])
        ) {
            return response()->json(
                $single ? null : []
            );
        }

        $place = $this->normalizeNominatimPlace(
            $result
        );

        return response()->json(
            $single
                ? $place
                : [$place]
        );
    }

    /**
     * Send request to Nominatim.
     *
     * Results are cached because the public Nominatim service
     * requires clients to cache repeated requests.
     */
    private function nominatimRequest(
        string $endpoint,
        array $params
    ): array {
        /**
         * Prefer NOMINATIM_URL from .env.
         *
         * Fallback:
         * https://nominatim.openstreetmap.org
         */
        $baseUrl = rtrim(
            env('NOMINATIM_URL')
                ?: 'https://nominatim.openstreetmap.org',
            '/'
        );

        /**
         * Prefer NOMINATIM_USER_AGENT from .env.
         *
         * Fallback:
         * MyFleetbaseDevTest/0.1 (it.service@kiwifood.net)
         */
        $userAgent = env('NOMINATIM_USER_AGENT')
            ?: 'MyFleetbaseDevTest/0.1 (it.service@kiwifood.net)';

        $cacheKey = sprintf(
            'fleetbase:nominatim:%s:%s',
            $endpoint,
            sha1(json_encode($params))
        );

        return Cache::remember(
            $cacheKey,
            now()->addHours(24),
            function () use (
                $baseUrl,
                $endpoint,
                $params,
                $userAgent
            ) {
                return $this->performNominatimRequest(
                    $baseUrl,
                    $endpoint,
                    $params,
                    $userAgent
                );
            }
        );
    }

    /**
     * Perform the actual HTTP call.
     *
     * Public nominatim.openstreetmap.org is limited to roughly
     * one request per second, so requests are serialized.
     *
     * Self-hosted Nominatim does not use this public throttle.
     */
    private function performNominatimRequest(
        string $baseUrl,
        string $endpoint,
        array $params,
        string $userAgent
    ): array {
        $request = function () use (
            $baseUrl,
            $endpoint,
            $params,
            $userAgent
        ) {
            $response = Http::withHeaders([
                'User-Agent' => $userAgent,
                'Accept'     => 'application/json',
            ])
                ->timeout(10)
                ->get(
                    "{$baseUrl}/{$endpoint}",
                    $params
                );

            $response->throw();

            return $response->json() ?? [];
        };

        /**
         * If this is NOT the public Nominatim instance,
         * do not apply the public service throttling logic.
         *
         * Useful when later switching to:
         *
         * NOMINATIM_URL=http://nominatim:8080
         *
         * or:
         *
         * NOMINATIM_URL=https://nominatim.example.com
         */
        if (
            !str_contains(
                $baseUrl,
                'nominatim.openstreetmap.org'
            )
        ) {
            return $request();
        }

        /**
         * Public Nominatim policy:
         * approximately maximum one request per second.
         *
         * Cache::lock is used so multiple requests from the
         * Fleetbase application don't hit Nominatim simultaneously.
         */
        return Cache::lock(
            'fleetbase:nominatim:public-request-lock',
            10
        )->block(
            10,
            function () use ($request) {
                $lastRequestAt = (float) Cache::get(
                    'fleetbase:nominatim:last-request-at',
                    0
                );

                $elapsed =
                    microtime(true)
                    - $lastRequestAt;

                if ($elapsed < 1.05) {
                    usleep(
                        (int) (
                            (1.05 - $elapsed)
                            * 1_000_000
                        )
                    );
                }

                try {
                    return $request();
                } finally {
                    Cache::put(
                        'fleetbase:nominatim:last-request-at',
                        microtime(true),
                        60
                    );
                }
            }
        );
    }

    /**
     * Convert Nominatim JSON into a Fleetbase-compatible
     * place representation.
     *
     * IMPORTANT:
     * GeoJSON coordinate order is:
     *
     * [longitude, latitude]
     */
    private function normalizeNominatimPlace(
        array $result
    ): ?array {
        if (
            !isset($result['lat']) ||
            !isset($result['lon'])
        ) {
            return null;
        }

        $address = $result['address'] ?? [];

        $latitude =
            (float) $result['lat'];

        $longitude =
            (float) $result['lon'];

        $houseNumber =
            $address['house_number']
            ?? null;

        $road =
            $address['road']
            ?? $address['pedestrian']
            ?? $address['residential']
            ?? $address['footway']
            ?? $address['path']
            ?? null;

        $street1 = trim(
            implode(
                ' ',
                array_filter([
                    $houseNumber,
                    $road,
                ])
            )
        );

        if (blank($street1)) {
            $street1 =
                $result['name']
                ?? $result['display_name']
                ?? null;
        }

        $city =
            $address['city']
            ?? $address['town']
            ?? $address['village']
            ?? $address['municipality']
            ?? $address['county']
            ?? null;

        $province =
            $address['state']
            ?? $address['region']
            ?? $address['state_district']
            ?? null;

        $neighborhood =
            $address['neighbourhood']
            ?? $address['suburb']
            ?? $address['quarter']
            ?? $address['city_district']
            ?? null;

        $countryCode =
            isset($address['country_code'])
                ? strtoupper(
                    $address['country_code']
                )
                : null;

        return [
            'name' =>
                $result['name']
                ?? $street1
                ?? $result['display_name']
                ?? null,

            'street1' =>
                $street1,

            'street2' =>
                null,

            'city' =>
                $city,

            'province' =>
                $province,

            'postal_code' =>
                $address['postcode']
                ?? null,

            'neighborhood' =>
                $neighborhood,

            'building' =>
                $houseNumber,

            'country' =>
                $countryCode,

            /**
             * Keep exactly the structure expected by
             * CoordinatesInputComponent.
             *
             * GeoJSON:
             *
             * [longitude, latitude]
             */
            'location' => [
                'type' => 'Point',

                'coordinates' => [
                    $longitude,
                    $latitude,
                ],
            ],

            /**
             * Optional OSS metadata.
             */
            'provider' =>
                'nominatim',

            'display_name' =>
                $result['display_name']
                ?? null,

            'osm_type' =>
                $result['osm_type']
                ?? null,

            'osm_id' =>
                $result['osm_id']
                ?? null,
        ];
    }

    /**
     * Original Fleetbase reverse geocoder.
     *
     * DO NOT CHANGE:
     * Used by original geocoder/reverse.
     */
    protected function reverseGeocode(
        float $latitude,
        float $longitude
    ) {
        return Geocoder::reverse(
            $latitude,
            $longitude
        )->get();
    }

    /**
     * Original Fleetbase forward geocoder.
     *
     * DO NOT CHANGE:
     * Used by original geocoder/query.
     */
    protected function forwardGeocode(
        string $query
    ) {
        return Geocoder::geocode(
            $query
        )->get();
    }

    /**
     * Original Fleetbase Google address converter.
     */
    protected function placeFromGoogleAddress(
        $googleAddress
    ) {
        return Place::createFromGoogleAddress(
            $googleAddress
        );
    }
}