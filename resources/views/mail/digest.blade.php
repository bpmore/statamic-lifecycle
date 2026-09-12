<x-mail::message>
# {{ $digest->subject() }}

Hello {{ $digest->recipientName }},

@php($own = $digest->count() - count($escalated) - count($notices))
@if (count($notices))
## Unless reviewed, these pages will change ({{ count($notices) }})

Their collections have chosen what happens to a page that stays overdue. Reviewing a page — even to say it is still current — stops this.

@foreach ($notices as $item)
- **{{ $item->title }}** — {{ $item->collection }} — Lifecycle will {{ $item->action?->label() }} on or after {{ $item->actionableFrom?->format('j M Y') }}@if ($item->editUrl) — [Review it]({{ $item->editUrl }})@endif

@endforeach
@endif
@if ($own > 0)
You are the content owner of {{ $own === 1 ? 'a page' : $own.' pages' }} that {{ $own === 1 ? 'needs' : 'need' }} a person to confirm {{ $own === 1 ? 'it is' : 'they are' }} still accurate. Open a page, read it, and press **Mark reviewed** in the sidebar — it takes a minute, and the record is what an auditor asks for.

@endif
@if (count($escalated))
## Escalated to you ({{ count($escalated) }})

These pages are overdue for review and their owners have not confirmed them. You are hearing about them as {{ $own > 0 ? 'the escalation contact' : 'the escalation contact for them' }}: a word with the owner, or a review of your own, settles it.

@foreach ($escalated as $item)
- **{{ $item->title }}** — {{ $item->collection }} — owned by {{ $item->ownerName ?? 'nobody' }} — was due {{ $item->dueAt->format('j M Y') }}@if ($item->editUrl) — [Review it]({{ $item->editUrl }})@endif

@endforeach
@endif
@if (count($overdue))
## Overdue ({{ count($overdue) }})

These were due and have not been confirmed.

@foreach ($overdue as $item)
- **{{ $item->title }}** — {{ $item->collection }} — was due {{ $item->dueAt->format('j M Y') }}@if ($item->editUrl) — [Review it]({{ $item->editUrl }})@endif

@endforeach
@endif
@if (count($dueToday))
## Due today ({{ count($dueToday) }})

@foreach ($dueToday as $item)
- **{{ $item->title }}** — {{ $item->collection }}@if ($item->editUrl) — [Review it]({{ $item->editUrl }})@endif

@endforeach
@endif
@if (count($dueSoon))
## Due soon ({{ count($dueSoon) }})

@foreach ($dueSoon as $item)
- **{{ $item->title }}** — {{ $item->collection }} — due {{ $item->dueAt->format('j M Y') }}@if ($item->editUrl) — [Review it]({{ $item->editUrl }})@endif

@endforeach
@endif

You get one of these a day at most, listing everything at once, and nothing when there is nothing. To hear weekly instead, or not by email at all, change *Review reminders* in [your preferences]({{ $preferencesUrl }}) — the dashboard shows these pages whatever you choose.

Thanks,<br>
{{ $siteName }}
</x-mail::message>
