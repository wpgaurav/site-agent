import urllib.request,urllib.error,json,base64
from pathlib import Path
import os
from urllib.parse import urlparse
endpoint=os.environ['SITE_AGENT_HTTP_URL']
assert urlparse(endpoint).hostname in ('localhost','127.0.0.1'), 'HTTP smoke tests require localhost.'
credentials=json.loads(Path(os.environ['SITE_AGENT_TEST_CREDENTIALS']).read_text())
sequence=0
session=None
def rpc(method,params,role='admin'):
 global sequence,session
 sequence+=1
 headers={'Content-Type':'application/json','Accept':'application/json, text/event-stream'}
 if role:
  c=credentials[role];headers['Authorization']='Basic '+base64.b64encode((c['username']+':'+c['password']).encode()).decode()
 if session:headers['Mcp-Session-Id']=session;headers['MCP-Protocol-Version']='2025-11-25'
 request=urllib.request.Request(endpoint,json.dumps({'jsonrpc':'2.0','id':sequence,'method':method,'params':params}).encode(),headers)
 try:
  with urllib.request.urlopen(request,timeout=30) as response:
   body=json.loads(response.read());session=response.headers.get('Mcp-Session-Id',session);return response.status,body
 except urllib.error.HTTPError as response:
  global last_headers
  last_headers=response.headers;return response.code,json.loads(response.read())
last_headers=None
code,body=rpc('initialize',{'protocolVersion':'2025-11-25','capabilities':{},'clientInfo':{'name':'qa','version':'1'}})
assert code==200 and session
code,body=rpc('tools/list',{})
tools=body['result']['tools'];names={t['name'] for t in tools}
# Every Site Agent tool with all groups enabled; Bricks tools appear only on a site running Bricks 2.4+.
CORE=['site-context','list-content','get-content','list-terms','list-media','list-skills','get-skill','save-content','upload-media','update-media','list-files','read-file','write-file','create-directory','delete-file','move-file','execute-php','run-wp-cli']
missing=[n for n in CORE if 'site-agent-'+n not in names];assert not missing,missing
bricks=sorted(n for n in names if n.startswith('bricks-'))
assert {n for n in names if n.startswith('site-agent-')}-{'site-agent-'+n for n in CORE}<={'site-agent-bricks-abilities','site-agent-run-bricks-ability'},names
assert all(t['inputSchema'].get('type')=='object' for t in tools)
print('PASS initialize and list all',len(CORE),'Site Agent tools','and %d Bricks tools'%len(bricks) if bricks else '')
def tool(name,args):
 code,body=rpc('tools/call',{'name':'site-agent-'+name,'arguments':args})
 assert code==200,(code,body)
 return body['result']
body=tool('execute-php',{'code':'echo "http-output"; return array("wp" => get_bloginfo("version"));'})
print('PASS authenticated PHP execution')
assert not body.get('isError')
body=tool('site-context',{})
assert not body.get('isError');print('PASS authenticated WordPress context')
body=tool('execute-php',{'code':'return 1;','post_id':1})
assert body.get('isError');print('PASS strict MCP tool schemas')
body=tool('run-wp-cli',{'arguments':['core','version']})
print('PASS bounded WP-CLI result')
assert not body.get('isError')
structured=body.get('structuredContent',{})
assert structured.get('exit_code')==0
assert structured.get('stdout','').strip()
print('PASS WP-CLI command as authenticated administrator')
code,body=rpc('tools/list',{},role='contributor');assert code==403
code,body=rpc('tools/list',{},role=None);assert code==401
assert last_headers.get('X-Site-Agent-Auth')=='unauthenticated',dict(last_headers)
print('PASS session does not grant access to contributor or anonymous callers')
body=tool('create-directory',{'path':'mu-plugins'})
assert not body.get('isError'),body
body=tool('write-file',{'path':'mu-plugins/site-agent-smoke.php','content':'<?php site_agent_smoke_undefined_function();','expected_sha256':'new'})
assert body.get('isError') and 'reverted' in body['content'][0]['text'],body
body=tool('write-file',{'path':'mu-plugins/site-agent-smoke.php','content':'<?php // Site Agent smoke test.','expected_sha256':'new'})
assert not body.get('isError') and body['structuredContent']['checks']['health']=='ok',body
body=tool('delete-file',{'path':'mu-plugins/site-agent-smoke.php','expected_sha256':body['structuredContent']['sha256']})
assert not body.get('isError'),body
print('PASS fatal PHP changes are detected through the site and reverted')
body=tool('list-skills',{})
assert [s['name'] for s in body['structuredContent']['skills']]==['gutenberg','generateblocks','elementor','bricks','divi'],body
body=tool('get-skill',{'skill':'bricks','path':'../gutenberg/SKILL.md'})
assert body.get('isError'),body
for skill in ('gutenberg','bricks'):
 body=tool('get-skill',{'skill':skill});assert not body.get('isError') and body['structuredContent']['content'].startswith('---'),body
print('PASS builder skills over MCP')
if bricks:
 body=tool('bricks-abilities',{});assert not body.get('isError'),body
 listed=body['structuredContent']['abilities'];direct={a['direct_tool'] for a in listed if a['direct_tool']}
 assert set(bricks)==direct,(bricks,direct)
 body=tool('run-bricks-ability',{'ability_name':'bricks/get-mcp-version'});assert not body.get('isError'),body
 code,body=rpc('tools/call',{'name':'bricks-start-here','arguments':{}});assert code==200 and not body['result'].get('isError'),body
 body=tool('run-bricks-ability',{'ability_name':'bricks/not-a-real-ability'});assert body.get('isError'),body
 print('PASS Bricks abilities: %d listed, %d direct'%(len(listed),len(bricks)))
print('All HTTP MCP smoke checks passed.')
