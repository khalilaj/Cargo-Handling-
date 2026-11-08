{{-- What has already gone into the tanks on this voyage, newest first, in Kenya time. --}}
@if ($pumps->isNotEmpty())
    <div class="bs-list">
        <h3>Pumped so far</h3>
        <div class="bs-table-wrap">
            <table class="bs-table">
                <thead>
                    <tr><th scope="col">When</th><th scope="col">Tank</th><th scope="col" class="num">Gross (kg)</th><th scope="col" class="num">Loss (kg)</th><th scope="col" class="num">Into tank (kg)</th></tr>
                </thead>
                <tbody>
                    @foreach ($pumps as $m)
                        <tr>
                            <td>{{ $m->occurred_at->copy()->timezone('Africa/Nairobi')->format('d M H:i') }}</td>
                            <td>{{ $m->tank?->code ?? '?' }}</td>
                            <td class="num">{{ number_format($m->gross_kg) }}</td>
                            <td class="num">{{ number_format($m->loss_kg) }}</td>
                            <td class="num">{{ number_format($m->net_kg) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
