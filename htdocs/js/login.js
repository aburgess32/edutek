/**
 * EduPak FRE-11: Login & Signup JavaScript
 * African Creature Avatar System + Step Wizard + Avatar Picker
 */

/* ========== AFRICAN CREATURE AVATAR SYSTEM ========== */
function hashName(name) {
  var h = 0;
  for (var i = 0; i < name.length; i++) {
    h = ((h << 5) - h + name.charCodeAt(i)) | 0;
  }
  return Math.abs(h);
}

/* Map avatar names to specific creatures */
var CREATURE_MAP = {
  'Simba': 0, 'Blaze': 0, 'Titan': 0, 'Imara': 0,
  'Arrow': 1, 'Haki': 1, 'Duma': 1,
  'Atlas': 2, 'Kibo': 2, 'Tendo': 2,
  'Bolt': 3, 'Storm': 3, 'Juma': 3, 'Tuma': 3,
  'Sage': 4, 'Wema': 4, 'Asili': 4,
  'Zola': 5, 'Tuzo': 5, 'Ivy': 5, 'Jade': 5,
  'Ember': 6, 'Mars': 6, 'Flare': 6,
  'Echo': 7, 'Pendo': 7, 'Nia': 7,
  'Apex': 8, 'Biko': 8, 'Wave': 8,
  'Zuri': 9, 'Keza': 9, 'Amani': 9,
  'Vega': 10, 'Raha': 10,
  'Nova': 11, 'Orbit': 11,
  'Nuru': 12, 'Asha': 12,
  'Lux': 13,
  'Ion': 14
};

function getCreatureIndex(name) {
  if (CREATURE_MAP.hasOwnProperty(name)) return CREATURE_MAP[name];
  return hashName(name) % 15;
}

function lightenColor(hex, amt) {
  hex = hex.replace('#','');
  var r = parseInt(hex.substring(0,2),16);
  var g = parseInt(hex.substring(2,4),16);
  var b = parseInt(hex.substring(4,6),16);
  r = Math.min(255, r + amt);
  g = Math.min(255, g + amt);
  b = Math.min(255, b + amt);
  return '#' + [r,g,b].map(function(c){ return c.toString(16).padStart(2,'0'); }).join('');
}

function darkenColor(hex, amt) {
  hex = hex.replace('#','');
  var r = parseInt(hex.substring(0,2),16);
  var g = parseInt(hex.substring(2,4),16);
  var b = parseInt(hex.substring(4,6),16);
  r = Math.max(0, r - amt);
  g = Math.max(0, g - amt);
  b = Math.max(0, b - amt);
  return '#' + [r,g,b].map(function(c){ return c.toString(16).padStart(2,'0'); }).join('');
}

function generateAvatar(name, size, color) {
  var bg = color || '#6366F1';
  var S = size;
  var cx = S / 2;
  var cy = S / 2;
  var r = S / 2;
  var sw = Math.max(1, S * 0.015);
  var creatureIdx = getCreatureIndex(name);
  var light = lightenColor(bg, 60);
  var dark = darkenColor(bg, 50);

  var svg = '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="' + bg + '"/>';

  switch(creatureIdx) {
    case 0: { // LION
      var maneR = S * 0.42;
      for (var i = 0; i < 12; i++) {
        var a = (i * 30) * Math.PI / 180;
        var x1 = cx + Math.cos(a) * maneR;
        var y1 = cy + Math.sin(a) * maneR;
        var x2 = cx + Math.cos(a - 0.25) * (maneR - S*0.12);
        var y2 = cy + Math.sin(a - 0.25) * (maneR - S*0.12);
        var x3 = cx + Math.cos(a + 0.25) * (maneR - S*0.12);
        var y3 = cy + Math.sin(a + 0.25) * (maneR - S*0.12);
        svg += '<polygon points="' + x1+','+y1+' '+x2+','+y2+' '+x3+','+y3 + '" fill="' + (i%2===0?'#fff':light) + '" opacity="0.85"/>';
      }
      svg += '<circle cx="' + cx + '" cy="' + (cy + S*0.02) + '" r="' + (S*0.28) + '" fill="' + light + '"/>';
      for (var i = 0; i < 12; i++) {
        var a = (i * 30 + 15) * Math.PI / 180;
        var lx1 = cx + Math.cos(a) * S*0.3;
        var ly1 = cy + Math.sin(a) * S*0.3;
        var lx2 = cx + Math.cos(a) * S*0.4;
        var ly2 = cy + Math.sin(a) * S*0.4;
        svg += '<line x1="'+lx1+'" y1="'+ly1+'" x2="'+lx2+'" y2="'+ly2+'" stroke="#fff" stroke-width="'+(sw*0.7)+'" opacity="0.5"/>';
      }
      svg += '<circle cx="'+(cx - S*0.1)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.04)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx + S*0.1)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.04)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx - S*0.09)+'" cy="'+(cy - S*0.03)+'" r="'+(S*0.015)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx + S*0.11)+'" cy="'+(cy - S*0.03)+'" r="'+(S*0.015)+'" fill="#fff"/>';
      svg += '<polygon points="'+cx+','+(cy + S*0.04)+' '+(cx - S*0.04)+','+(cy + S*0.1)+' '+(cx + S*0.04)+','+(cy + S*0.1)+'" fill="'+dark+'"/>';
      svg += '<path d="M'+(cx - S*0.08)+' '+(cy + S*0.14)+' Q'+cx+' '+(cy + S*0.22)+' '+(cx + S*0.08)+' '+(cy + S*0.14)+'" fill="none" stroke="'+dark+'" stroke-width="'+(sw*1.2)+'" stroke-linecap="round"/>';
      break;
    }
    case 1: { // EAGLE/HAWK
      for (var i = -3; i <= 3; i++) {
        var fx = cx + i * S*0.08;
        var fy = cy - S*0.32;
        svg += '<polygon points="'+fx+','+fy+' '+(fx - S*0.04)+','+(fy + S*0.18)+' '+(fx + S*0.04)+','+(fy + S*0.18)+'" fill="'+(i%2===0?'#fff':light)+'" opacity="0.8"/>';
      }
      var zigzag = 'M'+(cx - S*0.28)+' '+(cy - S*0.16);
      for (var i = 0; i < 7; i++) {
        var zx = cx - S*0.28 + i * S*0.09;
        zigzag += ' L'+(zx + S*0.045)+' '+(cy - S*0.22)+' L'+(zx + S*0.09)+' '+(cy - S*0.16);
      }
      svg += '<path d="'+zigzag+'" fill="none" stroke="#fff" stroke-width="'+sw+'" opacity="0.6"/>';
      var headY = cy - S*0.05;
      svg += '<ellipse cx="'+cx+'" cy="'+(headY + S*0.12)+'" rx="'+(S*0.24)+'" ry="'+(S*0.26)+'" fill="'+light+'"/>';
      svg += '<polygon points="'+cx+','+(cy + S*0.12)+' '+(cx - S*0.06)+','+(cy + S*0.02)+' '+(cx + S*0.06)+','+(cy + S*0.02)+'" fill="'+dark+'"/>';
      svg += '<polygon points="'+cx+','+(cy + S*0.2)+' '+(cx - S*0.04)+','+(cy + S*0.12)+' '+(cx + S*0.04)+','+(cy + S*0.12)+'" fill="#F7C948"/>';
      svg += '<ellipse cx="'+(cx - S*0.09)+'" cy="'+cy+'" rx="'+(S*0.04)+'" ry="'+(S*0.03)+'" fill="'+dark+'"/>';
      svg += '<ellipse cx="'+(cx + S*0.09)+'" cy="'+cy+'" rx="'+(S*0.04)+'" ry="'+(S*0.03)+'" fill="'+dark+'"/>';
      svg += '<line x1="'+(cx - S*0.14)+'" y1="'+(cy - S*0.04)+'" x2="'+(cx - S*0.05)+'" y2="'+(cy - S*0.06)+'" stroke="'+dark+'" stroke-width="'+(sw*1.5)+'" stroke-linecap="round"/>';
      svg += '<line x1="'+(cx + S*0.14)+'" y1="'+(cy - S*0.04)+'" x2="'+(cx + S*0.05)+'" y2="'+(cy - S*0.06)+'" stroke="'+dark+'" stroke-width="'+(sw*1.5)+'" stroke-linecap="round"/>';
      break;
    }
    case 2: { // ELEPHANT
      var earW = S * 0.22, earH = S * 0.28;
      svg += '<ellipse cx="'+(cx - S*0.28)+'" cy="'+(cy + S*0.02)+'" rx="'+earW+'" ry="'+earH+'" fill="'+light+'"/>';
      svg += '<circle cx="'+(cx - S*0.28)+'" cy="'+cy+'" r="'+(S*0.06)+'" fill="none" stroke="#fff" stroke-width="'+sw+'" opacity="0.6"/>';
      svg += '<circle cx="'+(cx - S*0.28)+'" cy="'+cy+'" r="'+(S*0.03)+'" fill="#fff" opacity="0.4"/>';
      svg += '<ellipse cx="'+(cx + S*0.28)+'" cy="'+(cy + S*0.02)+'" rx="'+earW+'" ry="'+earH+'" fill="'+light+'"/>';
      svg += '<circle cx="'+(cx + S*0.28)+'" cy="'+cy+'" r="'+(S*0.06)+'" fill="none" stroke="#fff" stroke-width="'+sw+'" opacity="0.6"/>';
      svg += '<circle cx="'+(cx + S*0.28)+'" cy="'+cy+'" r="'+(S*0.03)+'" fill="#fff" opacity="0.4"/>';
      svg += '<circle cx="'+cx+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.24)+'" fill="'+light+'"/>';
      svg += '<path d="M'+cx+' '+(cy + S*0.12)+' Q'+cx+' '+(cy + S*0.3)+' '+(cx + S*0.08)+' '+(cy + S*0.28)+' Q'+(cx + S*0.14)+' '+(cy + S*0.26)+' '+(cx + S*0.1)+' '+(cy + S*0.2)+'" fill="none" stroke="'+light+'" stroke-width="'+(S*0.06)+'" stroke-linecap="round"/>';
      svg += '<circle cx="'+(cx - S*0.1)+'" cy="'+(cy - S*0.05)+'" r="'+(S*0.035)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx + S*0.1)+'" cy="'+(cy - S*0.05)+'" r="'+(S*0.035)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx - S*0.09)+'" cy="'+(cy - S*0.06)+'" r="'+(S*0.012)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx + S*0.11)+'" cy="'+(cy - S*0.06)+'" r="'+(S*0.012)+'" fill="#fff"/>';
      svg += '<path d="M'+(cx - S*0.06)+' '+(cy + S*0.08)+' Q'+cx+' '+(cy + S*0.12)+' '+(cx + S*0.06)+' '+(cy + S*0.08)+'" fill="none" stroke="'+dark+'" stroke-width="'+sw+'" stroke-linecap="round"/>';
      break;
    }
    case 3: { // CHEETAH
      svg += '<ellipse cx="'+cx+'" cy="'+(cy + S*0.02)+'" rx="'+(S*0.3)+'" ry="'+(S*0.32)+'" fill="'+light+'"/>';
      svg += '<polygon points="'+(cx - S*0.22)+','+(cy - S*0.28)+' '+(cx - S*0.14)+','+(cy - S*0.08)+' '+(cx - S*0.3)+','+(cy - S*0.1)+'" fill="'+light+'"/>';
      svg += '<polygon points="'+(cx + S*0.22)+','+(cy - S*0.28)+' '+(cx + S*0.14)+','+(cy - S*0.08)+' '+(cx + S*0.3)+','+(cy - S*0.1)+'" fill="'+light+'"/>';
      svg += '<polygon points="'+(cx - S*0.21)+','+(cy - S*0.24)+' '+(cx - S*0.16)+','+(cy - S*0.1)+' '+(cx - S*0.27)+','+(cy - S*0.12)+'" fill="'+bg+'" opacity="0.5"/>';
      svg += '<polygon points="'+(cx + S*0.21)+','+(cy - S*0.24)+' '+(cx + S*0.16)+','+(cy - S*0.1)+' '+(cx + S*0.27)+','+(cy - S*0.12)+'" fill="'+bg+'" opacity="0.5"/>';
      var spots = [[-0.15,-0.08],[0.12,-0.1],[-0.08,0.08],[0.16,0.05],[-0.2,0.02],[0.06,-0.2],[-0.04,0.18],[0.22,-0.02]];
      for (var si = 0; si < spots.length; si++) {
        svg += '<circle cx="'+(cx + spots[si][0]*S)+'" cy="'+(cy + spots[si][1]*S)+'" r="'+(S*0.02)+'" fill="'+dark+'" opacity="0.5"/>';
      }
      svg += '<ellipse cx="'+(cx - S*0.1)+'" cy="'+(cy - S*0.02)+'" rx="'+(S*0.05)+'" ry="'+(S*0.04)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx - S*0.1)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.025)+'" fill="'+dark+'"/>';
      svg += '<ellipse cx="'+(cx + S*0.1)+'" cy="'+(cy - S*0.02)+'" rx="'+(S*0.05)+'" ry="'+(S*0.04)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx + S*0.1)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.025)+'" fill="'+dark+'"/>';
      svg += '<ellipse cx="'+cx+'" cy="'+(cy + S*0.08)+'" rx="'+(S*0.04)+'" ry="'+(S*0.025)+'" fill="'+dark+'"/>';
      svg += '<line x1="'+(cx - S*0.04)+'" y1="'+(cy + S*0.1)+'" x2="'+(cx - S*0.18)+'" y2="'+(cy + S*0.08)+'" stroke="'+dark+'" stroke-width="'+(sw*0.7)+'" opacity="0.4"/>';
      svg += '<line x1="'+(cx + S*0.04)+'" y1="'+(cy + S*0.1)+'" x2="'+(cx + S*0.18)+'" y2="'+(cy + S*0.08)+'" stroke="'+dark+'" stroke-width="'+(sw*0.7)+'" opacity="0.4"/>';
      svg += '<path d="M'+(cx - S*0.04)+' '+(cy + S*0.12)+' L'+cx+' '+(cy + S*0.15)+' L'+(cx + S*0.04)+' '+(cy + S*0.12)+'" fill="none" stroke="'+dark+'" stroke-width="'+sw+'" stroke-linecap="round"/>';
      break;
    }
    case 4: { // TORTOISE
      svg += '<ellipse cx="'+cx+'" cy="'+(cy - S*0.02)+'" rx="'+(S*0.38)+'" ry="'+(S*0.34)+'" fill="'+dark+'"/>';
      var hexR = S * 0.09;
      var hexCenters = [[0,-0.1],[-0.15,0.05],[0.15,0.05],[0,0.18],[-0.28,-0.05],[0.28,-0.05]];
      for (var hi = 0; hi < hexCenters.length; hi++) {
        var hcx = cx + hexCenters[hi][0] * S;
        var hcy = cy + hexCenters[hi][1] * S;
        var hex = '';
        for (var j = 0; j < 6; j++) {
          var ha = (j * 60 - 30) * Math.PI / 180;
          var px = hcx + Math.cos(ha) * hexR;
          var py = hcy + Math.sin(ha) * hexR;
          hex += (j===0?'M':'L') + px + ',' + py;
        }
        hex += 'Z';
        svg += '<path d="'+hex+'" fill="none" stroke="#fff" stroke-width="'+(sw*0.8)+'" opacity="0.4"/>';
      }
      svg += '<circle cx="'+cx+'" cy="'+(cy + S*0.18)+'" r="'+(S*0.16)+'" fill="'+light+'"/>';
      svg += '<circle cx="'+(cx - S*0.06)+'" cy="'+(cy + S*0.15)+'" r="'+(S*0.025)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx + S*0.06)+'" cy="'+(cy + S*0.15)+'" r="'+(S*0.025)+'" fill="'+dark+'"/>';
      svg += '<path d="M'+(cx - S*0.04)+' '+(cy + S*0.22)+' Q'+cx+' '+(cy + S*0.26)+' '+(cx + S*0.04)+' '+(cy + S*0.22)+'" fill="none" stroke="'+dark+'" stroke-width="'+sw+'" stroke-linecap="round"/>';
      break;
    }
    case 5: { // CHAMELEON
      svg += '<ellipse cx="'+(cx + S*0.02)+'" cy="'+(cy + S*0.05)+'" rx="'+(S*0.3)+'" ry="'+(S*0.22)+'" fill="'+light+'"/>';
      var stripeColors = ['#fff', dark, '#fff', bg, '#fff'];
      for (var si2 = 0; si2 < 5; si2++) {
        var sy = cy - S*0.1 + si2 * S*0.08;
        svg += '<line x1="'+(cx - S*0.28)+'" y1="'+sy+'" x2="'+(cx + S*0.28)+'" y2="'+sy+'" stroke="'+stripeColors[si2]+'" stroke-width="'+(sw*0.8)+'" opacity="0.35"/>';
      }
      var eyeX = cx - S*0.06, eyeY2 = cy - S*0.02;
      svg += '<circle cx="'+eyeX+'" cy="'+eyeY2+'" r="'+(S*0.08)+'" fill="#fff"/>';
      svg += '<circle cx="'+eyeX+'" cy="'+eyeY2+'" r="'+(S*0.05)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+eyeX+'" cy="'+eyeY2+'" r="'+(S*0.02)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx + S*0.12)+'" cy="'+(cy - S*0.04)+'" r="'+(S*0.05)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx + S*0.12)+'" cy="'+(cy - S*0.04)+'" r="'+(S*0.03)+'" fill="'+dark+'"/>';
      svg += '<path d="M'+(cx + S*0.25)+' '+(cy + S*0.1)+' Q'+(cx + S*0.38)+' '+(cy - S*0.1)+' '+(cx + S*0.3)+' '+(cy - S*0.22)+' Q'+(cx + S*0.25)+' '+(cy - S*0.3)+' '+(cx + S*0.2)+' '+(cy - S*0.24)+'" fill="none" stroke="'+light+'" stroke-width="'+(S*0.04)+'" stroke-linecap="round"/>';
      svg += '<path d="M'+(cx - S*0.12)+' '+(cy + S*0.1)+' Q'+(cx - S*0.04)+' '+(cy + S*0.16)+' '+(cx + S*0.06)+' '+(cy + S*0.1)+'" fill="none" stroke="'+dark+'" stroke-width="'+sw+'" stroke-linecap="round"/>';
      break;
    }
    case 6: { // CROCODILE
      svg += '<ellipse cx="'+cx+'" cy="'+(cy - S*0.06)+'" rx="'+(S*0.18)+'" ry="'+(S*0.28)+'" fill="'+light+'"/>';
      svg += '<ellipse cx="'+cx+'" cy="'+(cy - S*0.32)+'" rx="'+(S*0.12)+'" ry="'+(S*0.08)+'" fill="'+light+'"/>';
      var scalePositions = [[0,-0.08],[-0.08,0.04],[0.08,0.04],[0,0.16],[-0.1,-0.18],[0.1,-0.18],[0,-0.28]];
      for (var si3 = 0; si3 < scalePositions.length; si3++) {
        var scx = cx + scalePositions[si3][0] * S, scy = cy + scalePositions[si3][1] * S;
        var ds = S * 0.035;
        svg += '<polygon points="'+scx+','+(scy - ds)+' '+(scx + ds)+','+scy+' '+scx+','+(scy + ds)+' '+(scx - ds)+','+scy+'" fill="none" stroke="#fff" stroke-width="'+(sw*0.7)+'" opacity="0.5"/>';
      }
      svg += '<circle cx="'+(cx - S*0.14)+'" cy="'+(cy - S*0.14)+'" r="'+(S*0.05)+'" fill="#F7C948"/>';
      svg += '<ellipse cx="'+(cx - S*0.14)+'" cy="'+(cy - S*0.14)+'" rx="'+(S*0.015)+'" ry="'+(S*0.035)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx + S*0.14)+'" cy="'+(cy - S*0.14)+'" r="'+(S*0.05)+'" fill="#F7C948"/>';
      svg += '<ellipse cx="'+(cx + S*0.14)+'" cy="'+(cy - S*0.14)+'" rx="'+(S*0.015)+'" ry="'+(S*0.035)+'" fill="'+dark+'"/>';
      for (var ti = -2; ti <= 2; ti++) {
        svg += '<line x1="'+(cx + ti*S*0.04)+'" y1="'+(cy + S*0.22)+'" x2="'+(cx + ti*S*0.04)+'" y2="'+(cy + S*0.26)+'" stroke="#fff" stroke-width="'+(sw*1.5)+'" stroke-linecap="round"/>';
      }
      svg += '<path d="M'+(cx - S*0.16)+' '+(cy + S*0.2)+' Q'+cx+' '+(cy + S*0.3)+' '+(cx + S*0.16)+' '+(cy + S*0.2)+'" fill="none" stroke="'+dark+'" stroke-width="'+(sw*1.2)+'" stroke-linecap="round"/>';
      break;
    }
    case 7: { // OWL
      svg += '<polygon points="'+(cx - S*0.18)+','+(cy - S*0.35)+' '+(cx - S*0.12)+','+(cy - S*0.12)+' '+(cx - S*0.24)+','+(cy - S*0.15)+'" fill="'+light+'"/>';
      svg += '<polygon points="'+(cx + S*0.18)+','+(cy - S*0.35)+' '+(cx + S*0.12)+','+(cy - S*0.12)+' '+(cx + S*0.24)+','+(cy - S*0.15)+'" fill="'+light+'"/>';
      svg += '<circle cx="'+cx+'" cy="'+(cy + S*0.02)+'" r="'+(S*0.3)+'" fill="'+light+'"/>';
      svg += '<circle cx="'+cx+'" cy="'+(cy + S*0.02)+'" r="'+(S*0.28)+'" fill="none" stroke="#fff" stroke-width="'+(sw*0.7)+'" opacity="0.3"/>';
      svg += '<circle cx="'+cx+'" cy="'+(cy + S*0.02)+'" r="'+(S*0.22)+'" fill="none" stroke="#fff" stroke-width="'+(sw*0.7)+'" opacity="0.25"/>';
      svg += '<circle cx="'+(cx - S*0.11)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.09)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx - S*0.11)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.05)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx - S*0.1)+'" cy="'+(cy - S*0.04)+'" r="'+(S*0.02)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx + S*0.11)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.09)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx + S*0.11)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.05)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx + S*0.12)+'" cy="'+(cy - S*0.04)+'" r="'+(S*0.02)+'" fill="#fff"/>';
      svg += '<polygon points="'+cx+','+(cy + S*0.06)+' '+(cx - S*0.04)+','+(cy + S*0.12)+' '+(cx + S*0.04)+','+(cy + S*0.12)+'" fill="#F7C948"/>';
      break;
    }
    case 8: { // RHINO
      svg += '<ellipse cx="'+cx+'" cy="'+(cy + S*0.04)+'" rx="'+(S*0.32)+'" ry="'+(S*0.3)+'" fill="'+light+'"/>';
      for (var ri = 0; ri < 6; ri++) {
        var ly = cy - S*0.12 + ri * S*0.07;
        svg += '<line x1="'+(cx - S*0.28)+'" y1="'+ly+'" x2="'+(cx + S*0.28)+'" y2="'+ly+'" stroke="#fff" stroke-width="'+(sw*0.6)+'" opacity="0.25"/>';
      }
      svg += '<polygon points="'+cx+','+(cy - S*0.38)+' '+(cx - S*0.05)+','+(cy - S*0.12)+' '+(cx + S*0.05)+','+(cy - S*0.12)+'" fill="#fff" opacity="0.9"/>';
      svg += '<polygon points="'+cx+','+(cy - S*0.18)+' '+(cx - S*0.03)+','+(cy - S*0.08)+' '+(cx + S*0.03)+','+(cy - S*0.08)+'" fill="#fff" opacity="0.7"/>';
      svg += '<ellipse cx="'+(cx - S*0.12)+'" cy="'+cy+'" rx="'+(S*0.04)+'" ry="'+(S*0.03)+'" fill="'+dark+'"/>';
      svg += '<ellipse cx="'+(cx + S*0.12)+'" cy="'+cy+'" rx="'+(S*0.04)+'" ry="'+(S*0.03)+'" fill="'+dark+'"/>';
      svg += '<line x1="'+(cx - S*0.18)+'" y1="'+(cy - S*0.06)+'" x2="'+(cx - S*0.08)+'" y2="'+(cy - S*0.04)+'" stroke="'+dark+'" stroke-width="'+(sw*1.5)+'" stroke-linecap="round"/>';
      svg += '<line x1="'+(cx + S*0.18)+'" y1="'+(cy - S*0.06)+'" x2="'+(cx + S*0.08)+'" y2="'+(cy - S*0.04)+'" stroke="'+dark+'" stroke-width="'+(sw*1.5)+'" stroke-linecap="round"/>';
      svg += '<ellipse cx="'+(cx - S*0.28)+'" cy="'+(cy - S*0.12)+'" rx="'+(S*0.06)+'" ry="'+(S*0.08)+'" fill="'+light+'"/>';
      svg += '<ellipse cx="'+(cx + S*0.28)+'" cy="'+(cy - S*0.12)+'" rx="'+(S*0.06)+'" ry="'+(S*0.08)+'" fill="'+light+'"/>';
      svg += '<line x1="'+(cx - S*0.08)+'" y1="'+(cy + S*0.14)+'" x2="'+(cx + S*0.08)+'" y2="'+(cy + S*0.14)+'" stroke="'+dark+'" stroke-width="'+(sw*1.2)+'" stroke-linecap="round"/>';
      break;
    }
    case 9: { // BUTTERFLY
      svg += '<ellipse cx="'+(cx - S*0.22)+'" cy="'+(cy - S*0.06)+'" rx="'+(S*0.2)+'" ry="'+(S*0.26)+'" fill="'+light+'" opacity="0.9"/>';
      svg += '<ellipse cx="'+(cx + S*0.22)+'" cy="'+(cy - S*0.06)+'" rx="'+(S*0.2)+'" ry="'+(S*0.26)+'" fill="'+light+'" opacity="0.9"/>';
      var wings = [[-0.22,-0.06],[0.22,-0.06]];
      for (var wi = 0; wi < wings.length; wi++) {
        var wcx2 = cx + wings[wi][0] * S, wcy2 = cy + wings[wi][1] * S;
        var ds2 = S * 0.06;
        svg += '<polygon points="'+wcx2+','+(wcy2 - ds2)+' '+(wcx2 + ds2)+','+wcy2+' '+wcx2+','+(wcy2 + ds2)+' '+(wcx2 - ds2)+','+wcy2+'" fill="#fff" opacity="0.5"/>';
        svg += '<polygon points="'+wcx2+','+(wcy2 - ds2*0.5)+' '+(wcx2 + ds2*0.5)+','+wcy2+' '+wcx2+','+(wcy2 + ds2*0.5)+' '+(wcx2 - ds2*0.5)+','+wcy2+'" fill="'+bg+'" opacity="0.4"/>';
      }
      var dirs = [-1,1];
      for (var di = 0; di < dirs.length; di++) {
        var dir = dirs[di];
        var wx2 = cx + dir * S * 0.22;
        var zigz = 'M'+wx2+' '+(cy - S*0.24);
        for (var zi = 0; zi < 4; zi++) {
          zigz += ' L'+(wx2 + dir*S*0.05)+' '+(cy - S*0.2 + zi*S*0.08)+' L'+(wx2 - dir*S*0.02)+' '+(cy - S*0.16 + zi*S*0.08);
        }
        svg += '<path d="'+zigz+'" fill="none" stroke="#fff" stroke-width="'+(sw*0.7)+'" opacity="0.4"/>';
      }
      svg += '<ellipse cx="'+cx+'" cy="'+cy+'" rx="'+(S*0.07)+'" ry="'+(S*0.22)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+cx+'" cy="'+(cy - S*0.16)+'" r="'+(S*0.09)+'" fill="'+light+'"/>';
      svg += '<line x1="'+(cx - S*0.04)+'" y1="'+(cy - S*0.24)+'" x2="'+(cx - S*0.1)+'" y2="'+(cy - S*0.36)+'" stroke="'+dark+'" stroke-width="'+sw+'" stroke-linecap="round"/>';
      svg += '<circle cx="'+(cx - S*0.1)+'" cy="'+(cy - S*0.36)+'" r="'+(S*0.02)+'" fill="'+dark+'"/>';
      svg += '<line x1="'+(cx + S*0.04)+'" y1="'+(cy - S*0.24)+'" x2="'+(cx + S*0.1)+'" y2="'+(cy - S*0.36)+'" stroke="'+dark+'" stroke-width="'+sw+'" stroke-linecap="round"/>';
      svg += '<circle cx="'+(cx + S*0.1)+'" cy="'+(cy - S*0.36)+'" r="'+(S*0.02)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx - S*0.03)+'" cy="'+(cy - S*0.17)+'" r="'+(S*0.02)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx + S*0.03)+'" cy="'+(cy - S*0.17)+'" r="'+(S*0.02)+'" fill="'+dark+'"/>';
      svg += '<path d="M'+(cx - S*0.02)+' '+(cy - S*0.12)+' Q'+cx+' '+(cy - S*0.1)+' '+(cx + S*0.02)+' '+(cy - S*0.12)+'" fill="none" stroke="'+dark+'" stroke-width="'+(sw*0.8)+'" stroke-linecap="round"/>';
      break;
    }
    case 10: { // SNAKE/COBRA
      svg += '<ellipse cx="'+cx+'" cy="'+(cy + S*0.04)+'" rx="'+(S*0.34)+'" ry="'+(S*0.28)+'" fill="'+light+'"/>';
      for (var sni = 0; sni < 10; sni++) {
        var sa = (sni * 36 - 90) * Math.PI / 180;
        var sx1 = cx + Math.cos(sa) * S * 0.14;
        var sy1 = (cy - S*0.02) + Math.sin(sa) * S * 0.14;
        var sx2 = cx + Math.cos(sa) * S * 0.3;
        var sy2 = (cy - S*0.02) + Math.sin(sa) * S * 0.24;
        svg += '<line x1="'+sx1+'" y1="'+sy1+'" x2="'+sx2+'" y2="'+sy2+'" stroke="#fff" stroke-width="'+(sw*0.8)+'" opacity="0.45"/>';
      }
      svg += '<ellipse cx="'+cx+'" cy="'+(cy - S*0.04)+'" rx="'+(S*0.14)+'" ry="'+(S*0.18)+'" fill="'+light+'"/>';
      svg += '<circle cx="'+(cx - S*0.07)+'" cy="'+(cy - S*0.08)+'" r="'+(S*0.045)+'" fill="#F7C948"/>';
      svg += '<ellipse cx="'+(cx - S*0.07)+'" cy="'+(cy - S*0.08)+'" rx="'+(S*0.015)+'" ry="'+(S*0.035)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx + S*0.07)+'" cy="'+(cy - S*0.08)+'" r="'+(S*0.045)+'" fill="#F7C948"/>';
      svg += '<ellipse cx="'+(cx + S*0.07)+'" cy="'+(cy - S*0.08)+'" rx="'+(S*0.015)+'" ry="'+(S*0.035)+'" fill="'+dark+'"/>';
      svg += '<line x1="'+cx+'" y1="'+(cy + S*0.06)+'" x2="'+cx+'" y2="'+(cy + S*0.16)+'" stroke="'+dark+'" stroke-width="'+sw+'" stroke-linecap="round"/>';
      svg += '<line x1="'+cx+'" y1="'+(cy + S*0.16)+'" x2="'+(cx - S*0.03)+'" y2="'+(cy + S*0.2)+'" stroke="'+dark+'" stroke-width="'+(sw*0.7)+'" stroke-linecap="round"/>';
      svg += '<line x1="'+cx+'" y1="'+(cy + S*0.16)+'" x2="'+(cx + S*0.03)+'" y2="'+(cy + S*0.2)+'" stroke="'+dark+'" stroke-width="'+(sw*0.7)+'" stroke-linecap="round"/>';
      break;
    }
    case 11: { // GIRAFFE
      svg += '<rect x="'+(cx - S*0.1)+'" y="'+(cy + S*0.05)+'" width="'+(S*0.2)+'" height="'+(S*0.4)+'" rx="'+(S*0.08)+'" fill="'+light+'"/>';
      var kSpots = [[-0.04,0.12],[0.04,0.22],[-0.04,0.32],[0.04,0.1],[-0.02,0.24]];
      for (var ki = 0; ki < kSpots.length; ki++) {
        svg += '<rect x="'+(cx + kSpots[ki][0]*S - S*0.03)+'" y="'+(cy + kSpots[ki][1]*S)+'" width="'+(S*0.06)+'" height="'+(S*0.05)+'" rx="'+(S*0.01)+'" fill="'+dark+'" opacity="0.4"/>';
      }
      svg += '<ellipse cx="'+cx+'" cy="'+(cy - S*0.06)+'" rx="'+(S*0.18)+'" ry="'+(S*0.16)+'" fill="'+light+'"/>';
      svg += '<line x1="'+(cx - S*0.08)+'" y1="'+(cy - S*0.2)+'" x2="'+(cx - S*0.08)+'" y2="'+(cy - S*0.32)+'" stroke="'+light+'" stroke-width="'+(S*0.03)+'" stroke-linecap="round"/>';
      svg += '<circle cx="'+(cx - S*0.08)+'" cy="'+(cy - S*0.33)+'" r="'+(S*0.025)+'" fill="'+dark+'"/>';
      svg += '<line x1="'+(cx + S*0.08)+'" y1="'+(cy - S*0.2)+'" x2="'+(cx + S*0.08)+'" y2="'+(cy - S*0.32)+'" stroke="'+light+'" stroke-width="'+(S*0.03)+'" stroke-linecap="round"/>';
      svg += '<circle cx="'+(cx + S*0.08)+'" cy="'+(cy - S*0.33)+'" r="'+(S*0.025)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx - S*0.08)+'" cy="'+(cy - S*0.06)+'" r="'+(S*0.03)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx - S*0.07)+'" cy="'+(cy - S*0.07)+'" r="'+(S*0.01)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx + S*0.08)+'" cy="'+(cy - S*0.06)+'" r="'+(S*0.03)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx + S*0.09)+'" cy="'+(cy - S*0.07)+'" r="'+(S*0.01)+'" fill="#fff"/>';
      svg += '<path d="M'+(cx - S*0.06)+' '+(cy + S*0.02)+' Q'+cx+' '+(cy + S*0.07)+' '+(cx + S*0.06)+' '+(cy + S*0.02)+'" fill="none" stroke="'+dark+'" stroke-width="'+sw+'" stroke-linecap="round"/>';
      svg += '<rect x="'+(cx - S*0.15)+'" y="'+(cy - S*0.14)+'" width="'+(S*0.04)+'" height="'+(S*0.03)+'" rx="'+(S*0.005)+'" fill="'+dark+'" opacity="0.3"/>';
      svg += '<rect x="'+(cx + S*0.11)+'" y="'+(cy - S*0.14)+'" width="'+(S*0.04)+'" height="'+(S*0.03)+'" rx="'+(S*0.005)+'" fill="'+dark+'" opacity="0.3"/>';
      break;
    }
    case 12: { // HIPPO
      svg += '<circle cx="'+cx+'" cy="'+(cy + S*0.02)+'" r="'+(S*0.34)+'" fill="'+light+'"/>';
      var potDots = [[-0.18,-0.12],[0.18,-0.12],[-0.24,0.04],[0.24,0.04],[-0.14,0.2],[0.14,0.2],[0,-0.22],[-0.28,-0.04],[0.28,-0.04]];
      for (var pi = 0; pi < potDots.length; pi++) {
        svg += '<circle cx="'+(cx + potDots[pi][0]*S)+'" cy="'+(cy + potDots[pi][1]*S)+'" r="'+(S*0.012)+'" fill="#fff" opacity="0.4"/>';
      }
      svg += '<ellipse cx="'+(cx - S*0.24)+'" cy="'+(cy - S*0.22)+'" rx="'+(S*0.07)+'" ry="'+(S*0.05)+'" fill="'+light+'"/>';
      svg += '<ellipse cx="'+(cx + S*0.24)+'" cy="'+(cy - S*0.22)+'" rx="'+(S*0.07)+'" ry="'+(S*0.05)+'" fill="'+light+'"/>';
      svg += '<ellipse cx="'+cx+'" cy="'+(cy + S*0.12)+'" rx="'+(S*0.18)+'" ry="'+(S*0.12)+'" fill="'+darkenColor(light, 20)+'"/>';
      svg += '<circle cx="'+(cx - S*0.06)+'" cy="'+(cy + S*0.1)+'" r="'+(S*0.025)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx + S*0.06)+'" cy="'+(cy + S*0.1)+'" r="'+(S*0.025)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx - S*0.12)+'" cy="'+(cy - S*0.06)+'" r="'+(S*0.04)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx - S*0.11)+'" cy="'+(cy - S*0.07)+'" r="'+(S*0.015)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx + S*0.12)+'" cy="'+(cy - S*0.06)+'" r="'+(S*0.04)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx + S*0.13)+'" cy="'+(cy - S*0.07)+'" r="'+(S*0.015)+'" fill="#fff"/>';
      svg += '<path d="M'+(cx - S*0.12)+' '+(cy + S*0.18)+' Q'+cx+' '+(cy + S*0.28)+' '+(cx + S*0.12)+' '+(cy + S*0.18)+'" fill="none" stroke="'+dark+'" stroke-width="'+(sw*1.5)+'" stroke-linecap="round"/>';
      break;
    }
    case 13: { // MONKEY
      svg += '<circle cx="'+cx+'" cy="'+(cy + S*0.02)+'" r="'+(S*0.28)+'" fill="'+dark+'"/>';
      svg += '<ellipse cx="'+cx+'" cy="'+(cy + S*0.06)+'" rx="'+(S*0.2)+'" ry="'+(S*0.2)+'" fill="'+light+'"/>';
      svg += '<circle cx="'+(cx - S*0.3)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.1)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx - S*0.3)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.05)+'" fill="'+light+'" opacity="0.5"/>';
      svg += '<circle cx="'+(cx + S*0.3)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.1)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx + S*0.3)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.05)+'" fill="'+light+'" opacity="0.5"/>';
      svg += '<circle cx="'+cx+'" cy="'+(cy - S*0.12)+'" r="'+(S*0.04)+'" fill="none" stroke="#fff" stroke-width="'+(sw*0.7)+'" opacity="0.5"/>';
      svg += '<circle cx="'+cx+'" cy="'+(cy - S*0.12)+'" r="'+(S*0.015)+'" fill="#fff" opacity="0.4"/>';
      svg += '<circle cx="'+(cx - S*0.09)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.05)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx - S*0.09)+'" cy="'+(cy - S*0.01)+'" r="'+(S*0.03)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx + S*0.09)+'" cy="'+(cy - S*0.02)+'" r="'+(S*0.05)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx + S*0.09)+'" cy="'+(cy - S*0.01)+'" r="'+(S*0.03)+'" fill="'+dark+'"/>';
      svg += '<ellipse cx="'+cx+'" cy="'+(cy + S*0.06)+'" rx="'+(S*0.04)+'" ry="'+(S*0.025)+'" fill="'+dark+'"/>';
      svg += '<path d="M'+(cx - S*0.1)+' '+(cy + S*0.12)+' Q'+cx+' '+(cy + S*0.22)+' '+(cx + S*0.1)+' '+(cy + S*0.12)+'" fill="none" stroke="'+dark+'" stroke-width="'+(sw*1.3)+'" stroke-linecap="round"/>';
      break;
    }
    case 14: { // STARBIRD
      var starPoints = 5, outerR = S * 0.18, innerR = S * 0.08;
      var starPath = '';
      for (var sti = 0; sti < starPoints * 2; sti++) {
        var sta = (sti * 36 - 90) * Math.PI / 180;
        var rr = sti % 2 === 0 ? outerR : innerR;
        var spx = cx + Math.cos(sta) * rr;
        var spy = (cy - S*0.22) + Math.sin(sta) * rr;
        starPath += (sti===0?'M':'L') + spx + ',' + spy;
      }
      starPath += 'Z';
      svg += '<path d="'+starPath+'" fill="#fff" opacity="0.9"/>';
      for (var rli = 0; rli < 8; rli++) {
        var rla = (rli * 45) * Math.PI / 180;
        var rlx1 = cx + Math.cos(rla) * S * 0.2;
        var rly1 = cy + Math.sin(rla) * S * 0.2;
        var rlx2 = cx + Math.cos(rla) * S * 0.38;
        var rly2 = cy + Math.sin(rla) * S * 0.38;
        svg += '<line x1="'+rlx1+'" y1="'+rly1+'" x2="'+rlx2+'" y2="'+rly2+'" stroke="#fff" stroke-width="'+(sw*0.6)+'" opacity="0.3"/>';
      }
      svg += '<path d="M'+(cx - S*0.14)+' '+cy+' Q'+(cx - S*0.36)+' '+(cy - S*0.18)+' '+(cx - S*0.3)+' '+(cy + S*0.12)+'" fill="none" stroke="'+light+'" stroke-width="'+(S*0.05)+'" stroke-linecap="round" opacity="0.8"/>';
      svg += '<path d="M'+(cx + S*0.14)+' '+cy+' Q'+(cx + S*0.36)+' '+(cy - S*0.18)+' '+(cx + S*0.3)+' '+(cy + S*0.12)+'" fill="none" stroke="'+light+'" stroke-width="'+(S*0.05)+'" stroke-linecap="round" opacity="0.8"/>';
      svg += '<ellipse cx="'+cx+'" cy="'+(cy + S*0.08)+'" rx="'+(S*0.16)+'" ry="'+(S*0.2)+'" fill="'+light+'"/>';
      svg += '<circle cx="'+cx+'" cy="'+(cy - S*0.06)+'" r="'+(S*0.14)+'" fill="'+light+'"/>';
      svg += '<circle cx="'+(cx - S*0.06)+'" cy="'+(cy - S*0.08)+'" r="'+(S*0.04)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx - S*0.06)+'" cy="'+(cy - S*0.08)+'" r="'+(S*0.02)+'" fill="'+dark+'"/>';
      svg += '<circle cx="'+(cx + S*0.06)+'" cy="'+(cy - S*0.08)+'" r="'+(S*0.04)+'" fill="#fff"/>';
      svg += '<circle cx="'+(cx + S*0.06)+'" cy="'+(cy - S*0.08)+'" r="'+(S*0.02)+'" fill="'+dark+'"/>';
      svg += '<polygon points="'+cx+','+cy+' '+(cx - S*0.025)+','+(cy + S*0.04)+' '+(cx + S*0.025)+','+(cy + S*0.04)+'" fill="#F7C948"/>';
      break;
    }
  }

  return '<svg xmlns="http://www.w3.org/2000/svg" width="'+S+'" height="'+S+'" viewBox="0 0 '+S+' '+S+'" style="display:block;border-radius:50%;">'+svg+'</svg>';
}

/* ========== STEP WIZARD ========== */
function showStep(stepNum) {
  var steps = document.querySelectorAll('.wizard-step');
  for (var i = 0; i < steps.length; i++) {
    steps[i].classList.remove('active');
  }
  var target = document.getElementById('step-' + stepNum);
  if (target) target.classList.add('active');
}

/* ========== AVATAR PICKER ========== */
var selectedAvatarIdx = null;

function renderAvatarGrid(avatarData) {
  var grid = document.getElementById('avatar-grid');
  if (!grid) return;
  var html = '';
  for (var i = 0; i < avatarData.length; i++) {
    var a = avatarData[i];
    var bgStyle = 'background:' + a.color + '22;border:2px solid ' + a.color + '44;';
    html += '<div class="avatar-card" style="' + bgStyle + '" data-name="' + a.name + '" data-color="' + a.color + '" onclick="selectAvatarCard(this)">';
    html += '<div class="avatar-icon">' + generateAvatar(a.name, 48, a.color) + '</div>';
    html += '<span class="avatar-name-text" style="color:' + a.color + ';">' + a.name + '</span>';
    html += '</div>';
  }
  grid.innerHTML = html;
  selectedAvatarIdx = null;
  var confirm = document.getElementById('avatar-confirm');
  if (confirm) confirm.classList.remove('show');
}

function selectAvatarCard(el) {
  var cards = document.querySelectorAll('.avatar-card.selected');
  for (var i = 0; i < cards.length; i++) cards[i].classList.remove('selected');
  el.classList.add('selected');

  var name = el.getAttribute('data-name');
  var color = el.getAttribute('data-color');

  var hiddenName = document.getElementById('selected-avatar-name');
  var hiddenColor = document.getElementById('selected-avatar-color');
  if (hiddenName) hiddenName.value = name;
  if (hiddenColor) hiddenColor.value = color;

  var confirmBar = document.getElementById('avatar-confirm');
  var confirmBtn = document.getElementById('avatar-confirm-btn');
  if (confirmBar) confirmBar.classList.add('show');
  if (confirmBtn) confirmBtn.textContent = 'Meet My Avatar \u2014 ' + name;
}

function confirmAvatar() {
  var name = document.getElementById('selected-avatar-name');
  if (!name || !name.value) return;
  showMeetScreen(name.value, document.getElementById('selected-avatar-color').value);
}

/* ========== MEET YOUR AVATAR SCREEN ========== */
function showMeetScreen(avatarName, avatarColor) {
  var nameUpper = avatarName.toUpperCase();
  var userNameEl = document.getElementById('user-real-name');
  var realName = userNameEl ? userNameEl.value : '';

  var screen = document.getElementById('meet-screen-inner');
  if (screen) screen.style.background = 'linear-gradient(180deg, ' + avatarColor + '18 0%, ' + avatarColor + '08 40%, #F5F0EB 100%)';

  var meetAvatar = document.getElementById('meet-avatar');
  if (meetAvatar) meetAvatar.innerHTML = generateAvatar(avatarName, 120, avatarColor);

  var meetName = document.getElementById('meet-name');
  if (meetName) { meetName.textContent = nameUpper; meetName.style.color = avatarColor; }

  var meetReal = document.getElementById('meet-real-name');
  if (meetReal) meetReal.textContent = realName;

  // Bullet dot colors
  var style = document.createElement('style');
  style.textContent = '#meet-card li::before { background: ' + avatarColor + '; }';
  document.head.appendChild(style);

  var tag = document.getElementById('meet-tag');
  if (tag) { tag.textContent = 'Write it down: ' + nameUpper; tag.style.background = avatarColor + '18'; tag.style.color = avatarColor; }

  showStep(4);
  startCountdown();
}

/* ========== COUNTDOWN ========== */
var countdownInterval = null;
function startCountdown() {
  var btn = document.getElementById('btn-remember');
  var cd = document.getElementById('remember-countdown');
  if (!btn || !cd) return;
  btn.classList.remove('ready');
  var secs = 4;
  cd.textContent = 'Ready in ' + secs + '...';
  btn.textContent = 'Wait ' + secs + 's...';
  clearInterval(countdownInterval);
  countdownInterval = setInterval(function() {
    secs--;
    if (secs <= 0) {
      clearInterval(countdownInterval);
      cd.textContent = '';
      btn.textContent = 'Start Learning \u2192';
      btn.classList.add('ready');
    } else {
      cd.textContent = 'Ready in ' + secs + '...';
      btn.textContent = 'Wait ' + secs + 's...';
    }
  }, 1000);
}

function finishSignup() {
  var btn = document.getElementById('btn-remember');
  if (!btn || !btn.classList.contains('ready')) return;
  document.getElementById('register-form').submit();
}

/* ========== A-Z FILTER (find-user page) ========== */
function filterByLetter(letter) {
  var tabs = document.querySelectorAll('.alpha-tab');
  for (var i = 0; i < tabs.length; i++) {
    tabs[i].classList.toggle('active', tabs[i].textContent === letter);
  }
  var cards = document.querySelectorAll('.user-card');
  var anyVisible = false;
  for (var i = 0; i < cards.length; i++) {
    var name = cards[i].getAttribute('data-avatar') || '';
    if (letter === 'ALL' || name.charAt(0).toUpperCase() === letter) {
      cards[i].style.display = '';
      anyVisible = true;
    } else {
      cards[i].style.display = 'none';
    }
  }
  var empty = document.getElementById('user-grid-empty');
  if (empty) empty.style.display = anyVisible ? 'none' : 'block';
}

function searchUsers(query) {
  query = query.toLowerCase();
  var cards = document.querySelectorAll('.user-card');
  var anyVisible = false;
  // Clear active letter tab
  var tabs = document.querySelectorAll('.alpha-tab');
  for (var i = 0; i < tabs.length; i++) tabs[i].classList.remove('active');

  for (var i = 0; i < cards.length; i++) {
    var avatar = (cards[i].getAttribute('data-avatar') || '').toLowerCase();
    var real = (cards[i].getAttribute('data-real') || '').toLowerCase();
    if (!query || avatar.indexOf(query) !== -1 || real.indexOf(query) !== -1) {
      cards[i].style.display = '';
      anyVisible = true;
    } else {
      cards[i].style.display = 'none';
    }
  }
  var empty = document.getElementById('user-grid-empty');
  if (empty) empty.style.display = anyVisible ? 'none' : 'block';
}

/* ========== CONFIRM USER (find-user page) ========== */
function showConfirm(avatarName, avatarColor, userId) {
  var overlay = document.getElementById('confirm-overlay');
  if (!overlay) return;
  document.getElementById('confirm-avatar-display').innerHTML = generateAvatar(avatarName, 64, avatarColor);
  var nameEl = document.getElementById('confirm-name-display');
  nameEl.textContent = avatarName;
  nameEl.style.color = avatarColor;
  document.getElementById('confirm-user-id').value = userId;
  overlay.classList.add('show');
}

function closeConfirm() {
  var overlay = document.getElementById('confirm-overlay');
  if (overlay) overlay.classList.remove('show');
}
