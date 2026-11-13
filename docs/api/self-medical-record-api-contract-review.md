# API Contract Review — Self Medical Record

## Files generated

- `self-medical-record-openapi-1.2.0.yaml` — OpenAPI 3.1.0 with user and Admin routes.
- `self-medical-record-postman-collection-v2.1.json` — corrected Postman collection.
- `self-medical-record-frontend-api-guide.md` — base URL, authentication, endpoint map, examples, and fetch patterns.

## Validation performed

- Existing OpenAPI YAML parsed successfully before modification.
- Generated OpenAPI YAML parsed successfully after modification.
- Postman collection JSON parsed successfully after modification.
- OpenAPI paths: 31.
- OpenAPI operations (GET/POST/PUT/PATCH/DELETE): 42.
- Postman requests: 39.
- YAML/JSON parser validation passed. Full official OpenAPI 3.1 validator was not available in this runtime, so formal specification validation is not claimed.

## Items requiring backend confirmation

1. Postman upload currently had base_url ending in /api while Admin raw URLs also contained /api; corrected collection removes the duplicated segment.
2. Postman signed food image request contained a stale expiry/signature placeholder; corrected collection stores the returned image URL from upload response in food_image_url.
3. Uploaded Admin implementation hardening report lists POST /api/admin/test-connection, while uploaded Postman collection lists /api/admin/openmrs/test-connection. Contract uses /admin/test-connection based on the latest route listing in the report; verify with route:list.
4. Postman collection has no saved Admin response examples. Admin response data schemas remain extensible and need tightening after capturing actual controller responses.
5. Older OpenAPI contained production domain api.selfmedicalrecord.example.com as a placeholder; it was removed from servers to avoid implying that it is a real deployed domain.

## Important base URL rule

`base_url` already includes `/api`. All Postman paths therefore begin with `/admin/...`, `/login`, `/me`, etc., and must not repeat `/api`.
