import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('bookingHours', (initial) => ({
    hours: (() => {
        const days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        const out = {};
        for (const day of days) {
            const ranges = initial && initial[day] && initial[day].length
                ? initial[day]
                : [{ start: '', end: '' }];
            out[day] = ranges.map((r) => ({ start: r.start || '', end: r.end || '' }));
        }
        return out;
    })(),
}));

Alpine.start();
