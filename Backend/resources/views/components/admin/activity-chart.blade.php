@php
    $labels = $activities->pluck('date')->map(function ($date) {
        return \Carbon\Carbon::parse($date)->format('d M');
    });

    $totals = $activities->pluck('total');
@endphp

<div class="bg-white rounded-lg shadow p-5">

    <div class="flex items-center justify-between mb-4">

        <h3 class="font-bold text-lg">
            Chat Activity (7 Days)
        </h3>

        <span class="text-sm text-gray-500">
            Last 7 days
        </span>

    </div>

    <div class="h-80">
        <canvas id="chatActivityChart"></canvas>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    const ctx = document.getElementById('chatActivityChart');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: @json($labels),
            datasets: [{
                label: 'Messages',
                data: @json($totals),
                tension: 0.4,
                fill: true,
                borderWidth: 3,
                pointRadius: 5,
                pointHoverRadius: 7,
                backgroundColor: 'rgba(59,130,246,0.12)',
                borderColor: 'rgb(59,130,246)',
                pointBackgroundColor: 'rgb(59,130,246)'
            }]
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(148,163,184,0.15)'
                    },
                    ticks: {
                        precision: 0
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
</script>