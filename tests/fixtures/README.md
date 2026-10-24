# Contract fixtures

These files mirror the Odavent WordPress payload contract v1 (`data` of the API envelope: `payload-v1-*.json`, `events-list.json`). `ContractFixturesTest` checks their key sets against the contract list, so they cannot drift silently.
Update them (and the test's key lists) together whenever the Odavent contract gains or loses a key, and bump `Client::SUPPORTED_CONTRACT` when the version changes.
