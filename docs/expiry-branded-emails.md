# Branded payment-expiry emails — 3.45.2

New expiry warnings, cancellation messages and administrator reviews use the
existing MGF email header/logo/footer, booking table and technical mail headers.
Clear status and payment-record panels distinguish a cancellation from a payment
request. A cancelled hire has no checkout action; refund/retention decisions stay
separate. Warning links use the normal sign-in page, not generated payment or
access tokens. Admin links go to the existing booking review screen.

All message and booking text is escaped. Amounts are validated in minor units for
display. Default labels in other emails remain unchanged. Recipients, subjects,
event keys, policy deadlines and cancellation decisions are not changed.

Previously committed same-source outbox intents retain their original body and
delivery status across the formatting upgrade. They are not rewritten or resent;
source/recipient mismatches still fail closed. This also prevents repeated warning
checks from seeing an idempotency conflict solely because presentation changed.

Tests cover branding, exact supplied deadline, paid/outstanding values, escaping,
cancelled-payment wording, customer/admin actions, unchanged routing, legacy
outbox preservation and real SQL replay. Synthetic Chrome previews at desktop
and narrow widths are checked without any mail or external network traffic.
This is not a full Outlook/Gmail client certification. No historical resend is
part of deployment.
