# ADR 0004: Integrate Zabbix for Network Traffic Monitoring

## Status
Accepted

## Context
The IT operations team needs to visualize network interface traffic (in/out) for monitored devices. Zabbix is already deployed in the org.

Options considered:
1. **Direct Zabbix API calls** (chosen) — `ZabbixService` wraps JSON-RPC
2. Zabbix sender / trapper — push from app to Zabbix (wrong direction)
3. Export Zabbix data to separate TSDB (InfluxDB) — overkill

## Decision
Call Zabbix JSON-RPC API via `ZabbixService` from Laravel controllers.

- `TrafficController` — interface traffic charts (cached 30s)
- `MultiLatestValueController` — latest values for multiple items (cached 20s)
- Auth via Bearer token in `config/services.php`

## Consequences
- **Pros**: Reuses existing Zabbix investment, no new infra.
- **Cons**: Coupled to Zabbix API version; network latency on API calls (mitigated by caching).
- **Risk**: Zabbix API changes — wrap all calls in `ZabbixService` for single point of update.
