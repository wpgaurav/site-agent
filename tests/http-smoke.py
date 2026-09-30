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
 except urllib.error.HTTPError as response:return response.code,json.loads(response.read())
code,body=rpc('initialize',{'protocolVersion':'2025-11-25','capabilities':{},'clientInfo':{'name':'qa','version':'1'}})
assert code==200 and session
code,body=rpc('tools/list',{})
tools=body['result']['tools'];assert len(tools)==10
print('PASS initialize and list all 10 tools')
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
print('PASS session does not grant access to contributor or anonymous callers')
print('All HTTP MCP smoke checks passed.')
