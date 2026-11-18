# Intent: returns the most probable country of origin for each name in a list of names 
Author: A. Liang.  Status: accepted.

## Problem: 
User wants to find the most probable country of origin for each name in a list of names.  

## Proposed Outcome:
An application with a POST endpoint where the user sends a list of names and returns the most probable country of origin for each.

## Affected users and Systems:
The user does not need to be authenticated or authorized in order to use our endpoint.  There is no existing system.

## Constraints:
- The application will use the Laravel framework.
- The endpoint will be a POST /nationalize endpoint that accepts a list of names and returns the most probable country of origin for each name.
- The application will use the Nationalize.io API internally to determine the most probable country of origin for each.
- The Nationalize.io API endpoint that will be used is GET request to https://api.nationalize.io/.  An example of the request is:
```
GET https://api.nationalize.io/?name[]=nguyen&name[]=schmidt&name[]=garcia&apikey={key}
```
- the API key for Nationalize.io is stored in `.env`.
- The input to the POST /nationalize will be a JSON object that has a key of "names" which has a value of an array of strings.
- there will be a minimum of 1 name in the list of names.
- there will be a maximum of 5 names in the list of names.
- Empty strings and non-string values in the list of names must be rejected.
- The list of names may contain duplicates.  A name in the list should not be sent to the Nationalize.io API more than once.  The application will first check a cache to see if the name was recently used, and if so, will use the cached value instead of calling the Nationalize.io API.  This cache will be implemented using memcached.  The TTL for the entries in the cache will be 24 hours.
- other than the cache, the system will not persist or retain the results.
- Names may include whitespace.  Leading or trailing whitespace should be removed before calling the Nationalize.io API.
- the expected output will be in JSON format, where each name has the fully ranked list of countries sorted by probability in descending order, and key-values for the most probable country and its probability.  An example of the expected output is:
```json
{
    "results": [    
        {
            "name": "Mario",
            "most_probable_country": "IT",
            "probability": "83%",
            "countries": [
                { "country_id": "IT", "probability": "83%" },
                { "country_id": "AR", "probability": "6%" }
            ]
        }
    ]
}
```
- the number of countries returned per name should be limited to a maximum of 5.

## Open questions:
None blocking. Decisions made while writing `spec.md` (details and rationale are in spec §8):
- Each name may be at most 50 characters (after trimming).
- Names are normalized before deduplication, caching and the API call: trimmed, Unicode NFC, internal whitespace collapsed, and compared case-insensitively. The response echoes the trimmed input as sent.
- A name with no data returns `most_probable_country: null`, `probability: null`, `countries: []` (HTTP 200).
- If the Nationalize.io call fails, the whole request fails (503, or 502 for an invalid response); no partial results. If memcached fails, the application falls back to calling Nationalize.io.
- "Not sent more than once" applies within a single request. Concurrent requests for the same uncached name are not coordinated.
- Probabilities are integer percent strings, as in the example; no numeric field is added.
- No authentication and no rate limiting, as stated above; the unthrottled use of the API key's quota is an accepted risk.

Still needing an owner:
- Privacy sign-off for sending names to Nationalize.io and caching results for 24 hours.
- Whether audit logging will ever be required (it conflicts with not retaining names).

