# Licensing

Titan is licensed **by component role**, following the reasoning in
[`tasks/titan-wearable/05-opensource-legal.md`](tasks/titan-wearable/05-opensource-legal.md).

| Path | Licence | Why |
|---|---|---|
| everything not listed below (the Laravel server, web app, coach) | **AGPL-3.0** ([`LICENSE`](LICENSE)) | Network copyleft. You may self-host it freely; you may not run a modified closed fork as a paid service. The Nightscout model. |
| [`firmware/`](firmware) | **Apache-2.0** ([`firmware/LICENSE`](firmware/LICENSE)) | Carries an explicit patent grant with defensive termination — the sensible posture in a patent-dense field. |
| [`ios/`](ios) | **Apache-2.0** ([`ios/LICENSE`](ios/LICENSE)) | Client code should be frictionless to reuse and integrate. |
| [`biosignal/`](biosignal) | **Apache-2.0** ([`biosignal/LICENSE`](biosignal/LICENSE)) | Algorithms are meant to be reused and checked. Patent grant matters here too. |

Where a directory carries its own `LICENSE`, that file governs it and everything beneath it.
Everything else is AGPL-3.0.

## Contributing

By contributing you agree your contribution is licensed under the licence covering the path you
changed, and that you have the right to submit it.

## Not medical advice

Titan is a general-wellness and informational tool. It is **not a medical device**, and is not
intended to diagnose, treat, cure, mitigate, or prevent any disease. It is not a substitute for
professional medical advice. Always consult a qualified healthcare professional before changing your
health regimen. Measurements have limitations and are not guaranteed accurate. Any action you take is
at your own risk.

## A note on the data in this repository

The comments, tests and review notes cite real measured nights and workouts, because the thresholds in
this codebase only make sense next to the readings that forced them. Those readings are from the
project's own testers, are referred to only as "Tester B" / "Tester C", and carry no names, contact
details or identifiers. No user database, export or dump has ever been committed to this repository.
