# Auction Management API (Admin Portal)

Everything the web portal needs to run live livestock auctions: auction
centres (the markets), auction officers (the auctioneers), and auction
sessions (the planned market days with the animals to be sold).

Base URL: `https://<host>/api/v1/admin/entities`
Auth: `Authorization: Bearer <token>` of a `systemUser` account (Sanctum).
Every response uses the envelope `{ "status": true|false, "message": "...", "data": ... }`.
Validation errors return `422` with `errors`. Domain errors carry a `code`
(for example `CENTRE_NOT_FOUND`, `ANIMAL_ALREADY_LISTED`, `OFFICER_REQUIRED`).

## How the pieces fit

1. The portal creates **auction centres** and attaches **members** to them with a
   role: `manager`, `auctioneer` or `clerk`. Managers and auctioneers can run sessions.
2. **Auction officers** either register themselves from the app (they arrive as
   `verificationStatus: pending` with a `pending` membership at the centre they
   chose) or are created here, already verified. An officer cannot start an
   auction until verified.
3. The portal **plans sessions**: centre, assigned officer, title, date and the
   lots (animals by tag number, with starting price, minimum increment, optional
   reserve and bidding time). Each animal is locked so it cannot be listed twice.
4. In the app the officer selects their centre, sees the sessions assigned to
   them, and **begins** the auction on the day. Buyers bid live; when a lot
   sells, the sale belongs to the animal's farmer, who is texted the result.

---

## Auction centres

| Method | Path | Body / query | Notes |
| --- | --- | --- | --- |
| GET | `/auction-centres` | `q`, `status=active\|inactive`, `limit` | List with `membersCount`, `sessionsCount`, `liveCount` |
| POST | `/auction-centres` | `code`\*, `name`\*, `description`, `regionId`, `districtId`, `wardId`, `address`, `latitude`, `longitude`, `phone`, `status` | `code` is upper-cased and unique |
| GET | `/auction-centres/{centre}` | | Includes `members[]` |
| PUT | `/auction-centres/{centre}` | any of the fields above | |
| DELETE | `/auction-centres/{centre}` | | Deactivates instead when sessions exist |
| GET | `/auction-centres/{centre}/members` | | |
| POST | `/auction-centres/{centre}/members` | `userId` or `email` or `username`\*, `role`\* (`manager\|auctioneer\|clerk`), `status` | Upsert: re-posting changes the role |
| PUT | `/auction-centres/{centre}/members/{memberId}` | `role`, `status` (`pending\|active\|inactive`) | |
| DELETE | `/auction-centres/{centre}/members/{memberId}` | | |
| GET | `/auction-centres/{centre}/officers` | | Members who can be assigned a session, with `assignable` |

`{centre}` accepts the numeric id, the uuid or the code.

Centre object:

```json
{
  "id": 1, "uuid": "…", "code": "PUGU", "name": "Pugu Livestock Market",
  "description": null, "address": "Pugu, Dar es Salaam",
  "regionId": null, "regionName": null, "districtId": null, "districtName": null,
  "latitude": null, "longitude": null, "phone": null,
  "status": "active", "isActive": true,
  "membersCount": 3, "sessionsCount": 12, "liveCount": 0,
  "createdAt": "2026-11-03T14:00:00+03:00"
}
```

## Auction officers

| Method | Path | Body / query | Notes |
| --- | --- | --- | --- |
| GET | `/auction-officers` | `verificationStatus=pending\|verified\|rejected`, `status`, `centreUuid`, `q` | Returns `{ officers[], pendingCount }`, pending first |
| POST | `/auction-officers` | `centreUuid`\*, `firstName`\*, `lastName`\*, `phone`\*, `email`\*, `role`, `username`, `password`, `middleName`, `nationalId`, `licenceNo`, `address`, `regionId`, `districtId` | Creates the login account too, already verified; the password is texted to the officer |
| GET | `/auction-officers/{officer}` | | |
| PUT | `/auction-officers/{officer}` | personal fields, `status=active\|inactive` | `inactive` also freezes centre memberships |
| POST | `/auction-officers/{officer}/verify` | `decision`\* (`verified\|rejected`), `notes`, `centreUuid`, `role` | Approval activates the membership at `centreUuid` (defaults to the requested centre) and texts the officer |
| DELETE | `/auction-officers/{officer}` | | Deactivates |

`{officer}` accepts the numeric id, uuid or officer number (`AO-XXXXXX`).

Officer object:

```json
{
  "uuid": "…", "userId": 57, "officerNo": "AO-7K2Q9M",
  "firstName": "Juma", "lastName": "Dalali", "fullName": "Juma Dalali",
  "phone": "0700000001", "email": "juma@example.com",
  "nationalId": null, "licenceNo": "LIC-1", "hasLicenceDocument": false,
  "requestedCentre": { "uuid": "…", "code": "PUGU", "name": "…", "address": "…" },
  "verificationStatus": "pending", "isVerified": false, "status": "active",
  "verificationNotes": null, "verifiedAt": null,
  "centres": [ { "uuid": "…", "code": "PUGU", "name": "…", "myRole": "auctioneer",
                 "myMembershipStatus": "pending", "canHost": false } ]
}
```

## Auction sessions (planning)

| Method | Path | Body / query | Notes |
| --- | --- | --- | --- |
| GET | `/auction-sessions` | `centreUuid`, `status` (comma list of `scheduled,live,ended,cancelled`), `officerUserId`, `from`, `to`, `q` | Live first, then by date |
| POST | `/auction-sessions` | see below | Creates a `scheduled` session; `201` |
| GET | `/auction-sessions/{uuid}` | | With `lots[]`, `officer`, `centre` |
| PUT | `/auction-sessions/{uuid}` | `title`, `description`, `scheduledAt`, `paymentInstructions`, `currency`, `settings`, `officerUuid`/`officerUserId` | Only while `scheduled`; reassigning checks the officer can host at that centre |
| POST | `/auction-sessions/{uuid}/cancel` | | Releases animal locks; ends it if already live |
| POST | `/auction-sessions/{uuid}/lots` | lot fields (below) | `201` |
| PUT | `/auction-lots/{uuid}` | `title`, `description`, `startingPrice`, `minIncrement`, `reservePrice`, `durationSeconds` | Only queued lots |
| DELETE | `/auction-lots/{uuid}` | | Releases the animal lock |
| GET | `/auction-livestock?q=TAG` | `q` (min 2 chars), `limit` | Find animals by tag number or name across all farms, with owner and `held` |

Create body:

```json
{
  "centreUuid": "…",
  "officerUuid": "…",            // or "officerUserId": 57
  "title": "Pugu market day",
  "scheduledAt": "2026-11-15T09:00:00",
  "description": "Cattle and goats",
  "paymentInstructions": "Pay at the centre office before collecting the animal",
  "currency": "TZS",
  "settings": { "chat": true, "antiSnipeSeconds": 15, "extendBySeconds": 15, "paymentWindowHours": 48 },
  "lots": [
    { "identificationNumber": "55262622", "startingPrice": 500000, "minIncrement": 10000,
      "reservePrice": 650000, "durationSeconds": 120, "title": "Boran bull" },
    { "livestockUuid": "1764614904260-65568048", "startingPrice": 300000, "minIncrement": 5000 }
  ]
}
```

Lot fields: `identificationNumber` or `livestockUuid`\*, `startingPrice`\*,
`minIncrement`\*, `reservePrice`, `durationSeconds` (30–1800, default 120),
`title`, `description`. The reserve price is never shown to buyers.

Session object (abridged):

```json
{
  "uuid": "…", "title": "Pugu market day", "status": "scheduled",
  "scheduledAt": "…", "startedAt": null, "endedAt": null,
  "hostUserId": 57, "host": { "userId": 57, "name": "Juma Dalali" },
  "officer": { "uuid": "…", "officerNo": "AO-7K2Q9M", "fullName": "Juma Dalali", "phone": "…" },
  "centre": { "uuid": "…", "code": "PUGU", "name": "Pugu Livestock Market", "address": "…" },
  "plannedByPortal": true, "createdBy": 12,
  "currency": "TZS", "paymentInstructions": "…", "settings": { … },
  "lotsCount": 2, "soldCount": 0, "grossSales": 0, "viewers": 0,
  "lots": [ {
    "uuid": "…", "lotNumber": 1, "title": "Boran bull", "status": "queued",
    "livestockUuid": "…", "farmUuid": "…",
    "sellerUserId": 3, "seller": { "name": "Vallerian Mchau", "farmName": "Malaki", "phone": "…" },
    "livestock": { "name": "…", "identificationNumber": "55262622", "gender": "male", "speciesName": "Cattle", … },
    "startingPrice": 500000, "minIncrement": 10000, "reservePrice": 650000,
    "durationSeconds": 120, "currentPrice": 0, "bidCount": 0, "leadingBid": null
  } ]
}
```

Status lifecycle: `scheduled` → `live` (the officer presses Begin in the app)
→ `ended`. `cancelled` from the portal or the app while scheduled.

Common error codes: `CENTRE_NOT_FOUND`, `OFFICER_REQUIRED`, `OFFICER_NOT_ELIGIBLE`,
`CANNOT_HOST` (officer not verified), `ANIMAL_NOT_FOUND`, `ANIMAL_ALREADY_LISTED`
(animal is locked by another auction or marketplace listing), `DUPLICATE_LOT`,
`NOT_EDITABLE` (session no longer scheduled), `LOT_NOT_EDITABLE`.

## Physical auctions (market day) and weighing

A session has a `mode`: `live` (video + online bidding) or `physical` (in-person
market day, ported from the Shamba Bora instant-auction app). Pass `mode` when
planning. A physical session needs no media server: the officer presses Begin,
weighs each animal on the Bluetooth scale, records the buyer and price, and the
app prints the receipt. Online bidding is refused on physical sessions.

Lot objects carry `weight` (`{ kg, unit, stable, source: scale|manual, deviceName, at }`)
and `pricePerKg` once weighed. Sales carry `receiptNo` (`TS-YYYYMMDD-XXXXX`),
`channel`, `buyerName`, `buyerPhone`, `weightKg` and `pricePerKg`.

App-side endpoints under `/api/v1/auctions` (auctioneer only):

- `POST lots/{uuid}/weight-reading` `{kg, stable?}` — live needle, broadcast to viewers, not stored
- `POST lots/{uuid}/weight` `{kg, stable?, source?, deviceName?}` — locks the weight
- `POST lots/{uuid}/physical-sale` `{amount, buyerName, buyerPhone?, paid?, weightKg?}` — records the sale, returns the receipt
- `POST lots/{uuid}/pass` — unsold at the ring
- `GET sales/{uuidOrReceiptNo}/receipt` — receipt data (buyer, seller, officer, centre)
- `GET records?range=week|month|year&centreUuid=&q=` — sales with a period graph of weight and money

## App-side endpoints (for reference)

Under `/api/v1/auctions`, any signed-in account:

- `GET centres` active centres (for choosing where to apply), `GET centres/mine`
- `POST officers/apply`, `GET officers/me`, `PUT officers/me`
- `GET sessions?scope=assigned` sessions planned for the caller at a centre
- `POST sessions/{uuid}/start` the officer begins the auction

Self-registration also works at `POST /api/auth/register` with
`role: "auctionOfficer"`, `firstName`, `lastName`, `phone`, `email`, `username`,
`password` and `centreUuid`.

## Shell helpers on the server

```bash
php artisan auction:centre "Pugu Livestock Market" --code=PUGU --member=juma@example.com
php artisan auction:centre --list
php artisan auction:officer --list
php artisan auction:officer juma@example.com --approve --centre=PUGU
# Plan a session from the shell until the portal screen exists
php artisan auction:plan --centre=PUGU --officer=juma@example.com \
    --title="Pugu market day" --at="2026-11-05 09:00" \
    --lot=55262622:500000:10000 --lot=43562789:300000:5000:400000:90
# each --lot is TAG:startingPrice:minIncrement[:reservePrice[:durationSeconds]]
```
