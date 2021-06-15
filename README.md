# Install

> Download latest composer package from [here.](https://packagist.org/packages/noq/roomq)

# RoomQ Backend SDK - PHP

The [RoomQ](https://www.noq.hk/en/roomq) Backend SDK is used for server-side integration to your server. It was developed with PHP.

## High Level Logic

![The SDK Flow](https://raw.githubusercontent.com/redso/roomq.backend-sdk.nodejs/master/RoomQ-Backend-SDK-JS-high-level-logic-diagram.png)

1.  End user requests a page on your server
2.  The SDK verify if the request contain a valid ticket and in Serving state. If not, the SDK send him to the queue.
3.  End user obtain a ticket and wait in the queue until the ticket turns into Serving state.
4.  End user is redirected back to your website, now with a valid ticket
5.  The SDK verify if the request contain a valid ticket and in Serving state. End user stay in the requested page.
6.  The end user browses to a new page and the SDK continue to check if the ticket is valid.

## How to integrate

### Prerequisite

To integrate with the SDK, you need to have the following information provided by RoomQ

1.  ROOM_ID
2.  ROOM_SECRET
3.  ROOMQ_TICKET_ISSUER

### Major steps

To validate that the end user is allowed to access your site (has been through the queue) these steps are needed:

1.  Initialise RoomQ
2.  Determine if the current request page/path required to be protected by RoomQ
3.  Initialise Http Context Provider
4.  Validate the request
5.  If the end user should goes to the queue, set cache control
6.  Redirect user to queue

### Integration on specific path

It is recommended to integrate on the page/path which are selected to be provided. For the static files, e.g. images, css files, js files, ..., it is recommended to be skipped from the validation.
You can determine the requests type before pass it to the validation.

## Implementation Example

The following is an RoomQ integration example in express/nodejs.

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../noq/roomq/src/RoomQ.php';

use NoQ\RoomQ\RoomQ;

const ROOM_ID = "YOUR ROOM ID";
const ROOM_SECRET = "YOUR ROOM SECRET";
const ROOMQ_TICKET_ISSUER = "YOUR TICKET ISSUER";

$roomq = new RoomQ(ROOM_ID, ROOM_SECRET, ROOMQ_TICKET_ISSUER, false);

// The URL of the destination after user finish queuing in the waiting room
// If set null, the default behaviour is return to the current path
// If this is handling a POST request, it should not set null.
$returnURL = null;

$result = $roomq->validate($returnURL, "");
if ($result->needRedirect()) {
    header("Location: {$result->getRedirectURL()}");
    exit;
}

echo "Entered";
```

### Ajax calls

RoomQ doesn't support validate ticket in Ajax calls yet.

### Browser / CDN cache

If your responses are cached on browser or CDN, the new requests will not process by RoomQ.
In general, for the page / path integrated with RoomQ, you are not likely to cache the responses on CDN or browser.

### Delete Ticket / Extend Ticket

Delete or Extend Ticket are not supported in backend SDK integration.
