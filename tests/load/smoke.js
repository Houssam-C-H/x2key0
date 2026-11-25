// k6 load test (spec M19 "load tests", docs/RUNBOOK.md §5).
//
//   k6 run -e BASE_URL=https://staging.example.com tests/load/smoke.js
//   k6 run -e BASE_URL=... -e PROFILE=peak tests/load/smoke.js
//
// Browses like anonymous shoppers: home, game index, a category hub, search, a store page, news, sitemap.
// Thresholds follow the launch targets: p95 < 800 ms server time, < 1 % errors. Never run against production.
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'http://localhost';
const PROFILES = {
    smoke: { vus: 5, duration: '1m' },
    load: { stages: [{ duration: '2m', target: 50 }, { duration: '5m', target: 50 }, { duration: '1m', target: 0 }] },
    peak: { stages: [{ duration: '2m', target: 200 }, { duration: '5m', target: 200 }, { duration: '2m', target: 0 }] },
};

export const options = {
    ...(PROFILES[__ENV.PROFILE || 'smoke']),
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<800'],
        'checks{page:home}': ['rate>0.99'],
    },
};

const PAGES = ['/en', '/en/games', '/en/catalog/top-up', '/en/catalog/gift-cards', '/en/search?q=key', '/en/news', '/en/help', '/sitemap.xml'];

export default function () {
    const home = http.get(`${BASE}/en`, { tags: { page: 'home' } });
    check(home, { 'home 200': (r) => r.status === 200 }, { page: 'home' });

    for (let i = 0; i < 3; i++) {
        const path = PAGES[Math.floor(Math.random() * PAGES.length)];
        const res = http.get(`${BASE}${path}`, { tags: { page: path.split('?')[0] } });
        check(res, { 'status 200': (r) => r.status === 200 });
        sleep(1 + Math.random() * 2);
    }
}
