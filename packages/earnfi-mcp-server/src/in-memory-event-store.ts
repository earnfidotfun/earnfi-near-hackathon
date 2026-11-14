/**
 * In-memory EventStore for MCP stream resumability (single-process deployments).
 * For horizontal scale, replace with a shared store (Redis) implementing EventStore.
 */
import type { EventStore, EventId, StreamId } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import type { JSONRPCMessage } from '@modelcontextprotocol/sdk/types.js';

type Stored = { streamId: StreamId; message: JSONRPCMessage };

export class InMemoryEventStore implements EventStore {
  private readonly events = new Map<EventId, Stored>();

  private generateEventId(streamId: StreamId): EventId {
    return `${streamId}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
  }

  private getStreamIdFromEventId(eventId: EventId): StreamId {
    const parts = eventId.split('_');
    return parts.length > 0 ? (parts[0] as StreamId) : ('' as StreamId);
  }

  async storeEvent(streamId: StreamId, message: JSONRPCMessage): Promise<EventId> {
    const eventId = this.generateEventId(streamId);
    this.events.set(eventId, { streamId, message });
    return eventId;
  }

  async replayEventsAfter(
    lastEventId: EventId,
    { send }: { send: (eventId: EventId, message: JSONRPCMessage) => Promise<void> }
  ): Promise<StreamId> {
    if (!lastEventId || !this.events.has(lastEventId)) {
      return '' as StreamId;
    }
    const streamId = this.getStreamIdFromEventId(lastEventId);
    if (!streamId) {
      return '' as StreamId;
    }
    let foundLast = false;
    const sorted = [...this.events.entries()].sort((a, b) => a[0].localeCompare(b[0]));
    for (const [eventId, { streamId: sid, message }] of sorted) {
      if (sid !== streamId) continue;
      if (eventId === lastEventId) {
        foundLast = true;
        continue;
      }
      if (foundLast) {
        await send(eventId, message);
      }
    }
    return streamId;
  }
}
