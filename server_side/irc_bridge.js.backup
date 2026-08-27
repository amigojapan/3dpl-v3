/**
 * irc_bridge.js (fixed)
 *
 * Web client  -> WSS -> this bridge -> TCP -> IRC server
 * IRC server  -> TCP -> this bridge -> WSS -> Web client
 *
 * Client expects inbound WS JSON like: { "line": "<raw irc line>" }
 */

const fs = require("fs");
const https = require("https");
const net = require("net");
const WebSocket = require("ws");

// IRC target
const IRC_HOST = "irc.libera.chat";
const IRC_PORT = 6667;

// TLS options (same paths you had)
const server = https.createServer({
  key: fs.readFileSync("/etc/letsencrypt/live/swiss.korman.es-0001/privkey.pem"),
  cert: fs.readFileSync("/etc/letsencrypt/live/swiss.korman.es-0001/fullchain.pem"),
});

const wss = new WebSocket.Server({ server });

server.listen(9001, () => {
  console.log("WSS server running on wss://localhost:9001");
});

wss.on("connection", (ws) => {
  console.log("Browser connected");

  let irc = null;

// pending PM requests: reqId -> { target, ts }
const pendingPM = new Map();

  let channel = null;
  let nick = null;

  // Buffer for partial IRC lines (TCP can split them)
  let ircBuf = "";

  function sendToWS(obj) {
    if (ws && ws.readyState === WebSocket.OPEN) {
      ws.send(JSON.stringify(obj));
    }
  }

  function sendIRC(cmd) {
    if (!irc) return;
    console.log("SEND:", cmd);
    irc.write(cmd + "\r\n");
  }

  function cleanup() {
    if (irc) {
      try {
        irc.removeAllListeners();
        irc.destroy(); // important: destroy socket
      } catch (e) {}
      irc = null;
    }
    ircBuf = "";
    channel = null;
    nick = null;
  }

  ws.on("message", (msg) => {
    let data;
    try {
      data = JSON.parse(msg.toString("utf8"));
    } catch (e) {
      console.log("Bad JSON from browser:", msg.toString());
      return;
    }

    // Client -> bridge: connect request
    if (data.type === "connect") {
      // If reconnecting, clean up old socket first
      cleanup();

      nick = (data.nick || "").trim();
      channel = (data.channel || "").trim();

      if (!nick) {
        sendToWS({ type: "status", value: "error", reason: "Missing nick" });
        return;
      }

      // channel is optional (allows pure PM sessions)
      channel = channel || "";

      irc = net.connect(IRC_PORT, IRC_HOST, () => {
        // Standard registration
        sendIRC(`NICK ${nick}`);
        sendIRC(`USER ${nick} 0 * :${nick}`);
      });

      irc.on("data", (chunk) => {
        ircBuf += chunk.toString("utf8");

        // Split into complete lines; keep remainder in buffer
        const parts = ircBuf.split("\r\n");
        ircBuf = parts.pop(); // remainder (possibly partial line)

        for (const line of parts) {
          if (!line) continue;

          console.log("IRC:", line);

          // Always handle server PING
          if (line.startsWith("PING")) {
            // PING :server.name  -> PONG :server.name
            sendIRC("PONG " + line.substring(5));
            continue;
          }

          
          
          // Confirm JOIN (some networks may accept JOIN later than we send it)
          // Example: :nick!user@host JOIN :#channel
          const joinMatch = line.match(/^:([^!]+)!.*\sJOIN\s+:?(.+)$/);
          if (joinMatch) {
            const who = joinMatch[1];
            const chan = joinMatch[2];
            if (who === nick && chan === channel) {
              sendToWS({ type: "irc_ready", confirm: true }); // irc_ready_join_confirm
            }
          }
// PM error handling: if server rejects our PRIVMSG, report back to client
          // Common numerics: 401 no such nick, 403 no such channel, 404 cannot send, 477/716/717 registered/callerid restrictions
          const pmErr = line.match(/\s(401|403|404|477|716|717)\s+([^\s]+)\s+([^\s]+)\s+(.*)$/);
          if (pmErr) {
            const code = pmErr[1];
            const ourNick = pmErr[2];
            const target = pmErr[3];
            const msg = (pmErr[4] || "").replace(/^:/, "").trim();
            // Resolve any pending PM for this target
            for (const [reqId, info] of pendingPM.entries()) {
              if ((info.target || "") === target) {
                pendingPM.delete(reqId);
                sendToWS({ type: "pm_result", reqId, ok: false, line: `${code} ${target} ${msg}` });
              }
            }
            // still forward raw line
            sendToWS({ line });
            continue;
          }

// After welcome, join requested channel
          if (line.includes(" 001 ")) {
            // After welcome, optionally join a channel (channel may be empty for PM-only sessions)
            if (channel) {
              sendIRC(`JOIN ${channel}`);
            }
            // Notify clients that IRC is ready to accept outgoing messages
            sendToWS({ type: "irc_ready" });
            // also forward line to client
            sendToWS({ line });
            continue;
          }

          // End of NAMES list for our channel = fully joined
          // :server 366 <nick> #chan :End of /NAMES list.
          if (line.includes(" 366 ") && channel && line.includes(` ${channel} `)) {
            sendToWS({ type: "status", value: "connected" });
            // also forward line to client
            sendToWS({ line });
            continue;
          }

          // Forward ALL IRC lines to the web client in the format it expects
          // (Your client ignores messages without "line", so keep it simple)
          sendToWS({ line });
        }
      });

      irc.on("close", () => {
        sendToWS({ type: "status", value: "disconnected" });
        try { ws.close(); } catch (e) {}
        cleanup();
      });

      irc.on("error", (err) => {
        console.log("IRC socket error:", err?.message || err);
        sendToWS({ type: "status", value: "error", reason: "IRC socket error" });
        try { ws.close(); } catch (e) {}
        cleanup();
      });

      return;
    }

    // Client -> bridge: send a channel message
    if (data.type === "msg") {
      if (!irc || !channel) return;
      const text = (data.text ?? "").toString();

      // Prevent raw newlines from breaking IRC command
      const safe = text.replace(/[\r\n]+/g, " ").trim();
      if (!safe) return;

      sendIRC(`PRIVMSG ${channel} :${safe}`);
      return;
    }

    
    // Client -> bridge: send a private message (PM)
    if (data.type === "privmsg") {
      if (!irc) {
        const reqId = (data.reqId || "").toString();
        if (reqId) sendToWS({ type: "pm_result", reqId, ok: false, line: "bridge not ready (irc not connected yet)" });
        return;
      }
      const target = (data.target || "").toString().trim();
      const text = (data.text ?? "").toString();
      const reqId = (data.reqId || "").toString();

      // Keep target sane (basic IRC nick/channel charset)
      const safeTarget = target.replace(/[^A-Za-z0-9_\-\[\]\\`^{}|]/g, "").trim();

      // Prevent raw newlines from breaking IRC command
      const safeText = text.replace(/[\r\n]+/g, " ").trim();

      if (!safeTarget || !safeText) return;

      sendIRC(`PRIVMSG ${safeTarget} :${safeText}`);

      if (reqId) {
        pendingPM.set(reqId, { target: safeTarget, ts: Date.now() });
        // If no IRC error comes back soon, assume OK
        setTimeout(() => {
          const p = pendingPM.get(reqId);
          if (p) {
            pendingPM.delete(reqId);
            sendToWS({ type: "pm_result", reqId, ok: true });
          }
        }, 1200);
      }
      return;
    }

// Optional: client requests quit
    if (data.type === "quit") {
      if (irc) {
        sendIRC("QUIT :bye");
      }
      try { ws.close(); } catch (e) {}
      cleanup();
      return;
    }
  });

  ws.on("close", () => {
    console.log("Browser disconnected");
    cleanup();
  });

  ws.on("error", (err) => {
    console.log("WebSocket error:", err?.message || err);
    cleanup();
  });
});