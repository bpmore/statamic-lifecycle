<x-mail::message>
# Lifecycle refused to act

A run of expiry actions would have touched more of a collection than a single run is allowed to. Lifecycle changed nothing, and is telling you because that many pages falling due at once usually means a setting changed, not that the content did.

@foreach ($trips as $trip)
- **{{ $trip->collection }}** — {{ number_format($trip->affected) }} of {{ number_format($trip->size) }} entries ({{ (int) round($trip->share() * 100) }}%), against a limit of {{ (int) round($trip->limit * 100) }}% per run.
@endforeach

Look at why so many pages fell due at once before letting this go ahead. If it is expected, the limit is `expiry.circuit_breaker.max_share` in `config/lifecycle.php`, and the expiry command can be told to allow a collection for one run.

Thanks,<br>
{{ $siteName }}
</x-mail::message>
