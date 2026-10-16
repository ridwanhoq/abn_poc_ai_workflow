<script>
import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Legend,
    LinearScale,
    Tooltip,
} from 'chart.js';

Chart.register(
    BarController,
    BarElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
);

export default {
    name: 'ActivityChart',
    props: {
        activity: { type: Array, default: () => [] },
    },
    watch: {
        activity: {
            deep: true,
            handler() {
                this.render();
            },
        },
    },
    mounted() {
        this.render();
        this.observer = new MutationObserver(() => this.render());
        this.observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    },
    beforeUnmount() {
        this.chart?.destroy();
        this.observer?.disconnect();
    },
    methods: {
        render() {
            const dark = document.documentElement.classList.contains('dark');
            const grid = dark
                ? 'rgba(255,255,255,0.07)'
                : 'rgba(16,24,64,0.06)';
            const text = dark ? '#a5a9c4' : '#676879';
            const labels = this.activity.map((d) =>
                new Date(d.date + 'T00:00:00').toLocaleDateString(undefined, {
                    month: 'short',
                    day: 'numeric',
                }),
            );
            const dataset = (label, key, color) => ({
                label,
                data: this.activity.map((d) => d[key]),
                backgroundColor: color,
                borderRadius: 6,
                borderSkipped: false,
                maxBarThickness: 26,
            });

            this.chart?.destroy();
            this.chart = new Chart(this.$refs.canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        dataset('Success', 'success', '#00c875'),
                        dataset('Failed', 'failed', '#e2445c'),
                        dataset('Other', 'other', '#a2a2ff'),
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: text,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                boxWidth: 8,
                                padding: 16,
                            },
                        },
                        tooltip: { padding: 10, cornerRadius: 8 },
                    },
                    scales: {
                        x: {
                            stacked: true,
                            grid: { display: false },
                            ticks: {
                                color: text,
                                maxRotation: 0,
                                autoSkip: true,
                            },
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            grid: { color: grid },
                            border: { display: false },
                            ticks: { color: text, precision: 0 },
                        },
                    },
                },
            });
        },
    },
};
</script>

<template>
    <div class="relative h-64 w-full">
        <canvas ref="canvas" aria-label="Workflow activity chart" role="img" />
    </div>
</template>
