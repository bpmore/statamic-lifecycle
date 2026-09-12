<x-mail::message>
# {{ $digest->subject() }}

Hello {{ $digest->recipientName }},

You are the content owner of {{ $digest->count() === 1 ? 'a page' : $digest->count().' pages' }} that {{ $digest->count() === 1 ? 'needs' : 'need' }} a person to confirm {{ $digest->count() === 1 ? 'it is' : 'they are' }} still accurate. Open a page, read it, and press **Mark reviewed** in the sidebar — it takes a minute, and the record is what an auditor asks for.

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

You get one of these a day at most, listing everything at once, and nothing when there is nothing.

Thanks,<br>
{{ $siteName }}
</x-mail::message>
