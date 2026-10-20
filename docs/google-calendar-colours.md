# New-event Google colours

Opt-in per installation under Calendar Sync → Connection settings → Colours for new events.
Defaults: estate Green/Basil (Google event colour 10), Scouts Blue/Blueberry (9).
Disabled until an administrator saves the checkbox. Selecting Calendar default omits colorId.

Estate classification applies only in campsite mode: exact, trimmed, case-insensitive organisation labels (initially HP Business and Estate), or the existing generated staff estate-entry prefix. Other eligible campsite reservations are Scout bookings, even when scout_use is false. Internal Scout-use bookings can also be coloured; ordinary non-campsite paid hires keep the calendar default. Names, emails, free-text titles and keyword guesses are not classification inputs. Staff notes are inspected locally, never exported.

Saving colours is administrator/nonce/lock protected. It pauses sync, clears saved choices and invalidates the operator's preview. Every insertion reprojects with current colours immediately before the Google request. Comparison fingerprints catch another operator's stale preview. Configuration never enables sync or writes Google.

Only new POSTed events receive colorId. No PATCH/DELETE path is introduced. Existing linked/manual/estate events and their colours remain authoritative. Colour preferences are excluded from protected source hashes so enabling/changing this setting does not invalidate old links or trigger recolouring. Stable event IDs do not depend on colour. Booking/billing/eligibility and site settings remain independent.

The MGF reservation side of the comparison shows its projected colour. This is a new-event preference, not a promise to recolour the adjacent existing Google event.
