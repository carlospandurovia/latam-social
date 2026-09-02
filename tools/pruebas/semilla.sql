-- Datos minimos para ejecutar las pruebas de restriccion.
-- Reutilizable por todas las iteraciones. Vive en el repositorio a proposito:
-- las semillas en /tmp se pierden y las pruebas dejan de ser reproducibles.
SET NAMES utf8mb4;

INSERT INTO currencies (code,name,symbol,decimal_places,is_active) VALUES
 ('PEN','Sol peruano','S/',2,1),
 ('USD','Dolar estadounidense','$',2,1),
 ('COP','Peso colombiano','$',2,1);

-- 9.1: las fuentes de tipo de cambio. Van antes que nada financiero porque
-- `exchange_rates.source` es clave ajena contra ellas desde esa iteracion.
INSERT INTO fx_sources (code,name,description,is_active,created_at) VALUES
 ('sunat','SUNAT','Tipo de cambio publicado por SUNAT. Es el que la ley peruana pide citar.',1,NOW(3)),
 ('manual','Carga manual','Tecleado por una persona del equipo, con su nombre en la bitacora.',1,NOW(3));

INSERT INTO countries (iso2,iso3,numeric_code,name,phone_code,default_currency_code,timezone,is_active) VALUES
 ('PE','PER','604','Peru','+51','PEN','America/Lima',1),
 ('CO','COL','170','Colombia','+57','COP','America/Bogota',1),
 ('US','USA','840','Estados Unidos','+1','USD','America/New_York',1);

-- Contrasenas: hash bcrypt de un valor irrelevante, nunca texto plano ni
-- siquiera en una semilla de pruebas.
-- Contrasenas: un hash bcrypt sintetico, nunca texto plano ni en pruebas.
INSERT INTO users (uuid,name,user_type,email,password,status,created_at) VALUES
 (UUID(),'Operador Uno','internal','op1@ejemplo.test','$2y$12$0000000000000000000000u1SinValorRealNoEsUnaClave00',   'active',NOW(3)),
 (UUID(),'Operador Dos','internal','op2@ejemplo.test','$2y$12$0000000000000000000000u2SinValorRealNoEsUnaClave00',   'active',NOW(3)),
 (UUID(),'Aprobador',   'internal','aprob@ejemplo.test','$2y$12$0000000000000000000000u3SinValorRealNoEsUnaClave00','active',NOW(3));

INSERT INTO files (uuid,disk,path,original_name,mime_type,size_bytes,checksum_sha256,visibility,purpose,created_at) VALUES
 (UUID(),'local','pruebas/comprobante.pdf','comprobante.pdf','application/pdf',1024,REPEAT('a',64),'private','invoice',NOW(3)),
 -- 3.5: el documento de identidad y la evidencia de la aceptacion de terminos.
 -- Sin ellos un creador no puede estar activo, y la semilla trae creadores
 -- activos: si estos archivos faltan, la semilla no carga. Es a proposito.
 (UUID(),'local','pruebas/dni.pdf','dni.pdf','application/pdf',2048,REPEAT('b',64),'private','identity_document',NOW(3)),
 (UUID(),'local','pruebas/aceptacion.pdf','aceptacion.pdf','application/pdf',512,REPEAT('c',64),'private','terms_evidence',NOW(3));

-- 3.5 / DEC-059: los terminos vigentes. `effective_to IS NULL` es lo que los
-- hace los vigentes; publicar los siguientes cierra estos.
-- 9.16: PUBLICADA, con su responsable. Una fila sin `published_at` es un
-- borrador: no rige, no solapa con nada y no se puede cerrar.
INSERT INTO terms_versions (uuid,audience,code,version,title,body,content_sha256,effective_from,
   published_at,published_by_user_id,review_status,created_at)
 VALUES (UUID(),'creator','creator_terms','2026.1','Terminos del creador',
   'Texto de prueba de los terminos del creador.',REPEAT('d',64),'2026-01-01',
   NOW(3),(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u),'revisado',NOW(3));

INSERT INTO platform_brands (uuid,code,name,is_active,created_at) VALUES
 (UUID(),'LATAM','LATAM Social',1,NOW(3));

INSERT INTO legal_entities (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,tax_id_number,
   address_line1,city,default_currency_code,timezone,status,created_at)
 SELECT UUID(),pb.id,'CTS-PE','Soluciones Tecnologicas a Medida S.A.C.',c.id,'RUC','20603203896',
   'Por completar','Lima','PEN','America/Lima','active',NOW(3)
 FROM platform_brands pb, countries c WHERE pb.code='LATAM' AND c.iso2='PE';

-- 9.6: una SEGUNDA sociedad, sin cobertura de ningun pais.
--
-- Hace falta para poder probar el cruce que `BR-LE-009` prohibe --un lote de una
-- sociedad pagando el trabajo de una campana de otra-- y sin ella esa asercion
-- no se puede escribir. Sin cobertura a proposito: es exactamente el estado de
-- CTS Colombia mientras `Q-15` siga abierto, y anadirsela chocaria con
-- `uq_lec_country`.
INSERT INTO legal_entities (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,tax_id_number,
   address_line1,city,default_currency_code,timezone,status,created_at)
 SELECT UUID(),pb.id,'CTS-CO','CTS Colombia S.A.S.',c.id,'NIT','9001234567',
   'Por completar','Bogota','COP','America/Bogota','active',NOW(3)
 FROM platform_brands pb, countries c WHERE pb.code='LATAM' AND c.iso2='CO';

-- 9.12: los tipos de comprobante son DATOS y son de un pais. Antes eran cinco
-- palabras peruanas en un CHECK del codigo. Se siembran los dos de Peru --que
-- es donde se factura-- y uno de Colombia, que hace falta para poder afirmar
-- que una serie no cruza de pais.
-- 9.21b: la portada publica. Se siembra la de CREADORES porque la suite necesita
-- una pagina existente para probar los bloques, y porque `uq_lp_code` solo se
-- puede afirmar si ya hay una de marcas.
INSERT INTO landing_pages (platform_brand_id,code,headline,subheadline,cta_label,is_published,created_at)
 SELECT id,'marcas','Campanas con creadores, de principio a fin','Texto de prueba.','Quiero lanzar una campana',1,NOW(3)
 FROM platform_brands WHERE code='LATAM';

INSERT INTO landing_pages (platform_brand_id,code,headline,subheadline,cta_label,is_published,created_at)
 SELECT id,'creadores','Trabaja con marcas y cobra sin perseguir a nadie','Texto de prueba.','Quiero postular',1,NOW(3)
 FROM platform_brands WHERE code='LATAM';

INSERT INTO document_types (country_id,code,name,official_code,series_pattern,series_label,
   number_length,requires_customer_tax_id,is_active,sort_order,created_at)
 SELECT id,'invoice','Factura electronica','01','^F[A-Z0-9]{3}$','Serie: F y tres mas',8,1,1,10,NOW(3)
 FROM countries WHERE iso2='PE';

INSERT INTO document_types (country_id,code,name,official_code,series_pattern,series_label,
   number_length,requires_customer_tax_id,is_active,sort_order,created_at)
 SELECT id,'boleta','Boleta de venta electronica','03','^B[A-Z0-9]{3}$','Serie: B y tres mas',8,0,1,20,NOW(3)
 FROM countries WHERE iso2='PE';

-- 9.9f: las notas peruanas tambien.
--
-- Hacian falta desde que `tg_invoice_tipo_*` comprueba el tipo contra el
-- CATALOGO del pais (`T-79`): la suite 2.13 emite una `credit_note` para
-- afirmar que el mismo correlativo puede repetirse en otro tipo de documento, y
-- sin la fila en el catalogo esa asercion se caia por un motivo que no era el
-- suyo. Son tipos reales de SUNAT, no relleno de prueba: codigos oficiales 07 y
-- 08, y su serie va como la del comprobante que corrigen.
INSERT INTO document_types (country_id,code,name,official_code,number_length,
   requires_customer_tax_id,is_active,sort_order,created_at)
 SELECT id,'credit_note','Nota de credito electronica','07',8,1,1,30,NOW(3)
 FROM countries WHERE iso2='PE';

INSERT INTO document_types (country_id,code,name,official_code,number_length,
   requires_customer_tax_id,is_active,sort_order,created_at)
 SELECT id,'debit_note','Nota de debito electronica','08',8,1,1,40,NOW(3)
 FROM countries WHERE iso2='PE';

INSERT INTO document_types (country_id,code,name,official_code,number_length,is_active,sort_order,created_at)
 SELECT id,'invoice','Factura electronica','01',8,1,10,NOW(3) FROM countries WHERE iso2='CO';

INSERT INTO document_series (legal_entity_id,document_type_id,series,next_number,environment,
   is_active,is_default,created_at)
 SELECT le.id,dt.id,'F001',1,'production',1,1,NOW(3)
 FROM legal_entities le
 JOIN document_types dt ON dt.country_id = le.country_id AND dt.code = 'invoice'
 WHERE le.code='CTS-PE';

INSERT INTO client_organizations (uuid,commercial_name,client_code,country_id,status,created_at)
 SELECT UUID(),'Marca Demo S.A.','CLI-0001',id,'active',NOW(3) FROM countries WHERE iso2='PE';

INSERT INTO client_tax_profiles (client_organization_id,country_id,legal_name,tax_id_type,tax_id_number,
   address_line1,city,payment_term_days,valid_from,created_at)
 SELECT co.id,co.country_id,'Marca Demo S.A.','RUC','20123456789','Av. Demo 100','Lima',30,'2026-01-01',NOW(3)
 FROM client_organizations co WHERE co.client_code='CLI-0001';

INSERT INTO client_brands (uuid,client_organization_id,name,slug,status,created_at)
 SELECT UUID(),id,'Demo Brand','demo-brand','active',NOW(3) FROM client_organizations WHERE client_code='CLI-0001';

-- 3.5: un creador activo YA NO se declara activo y punto. `ck_creators_activation`
-- exige fecha y `ck_creators_active_identity` exige identidad verificada, que a
-- su vez exige revisor y documento adjunto (`ck_creators_identity_evidence`).
-- Antes de 3.5 estas dos filas decian 'active' sin nada detras y la base las
-- aceptaba; ahora, si se le quita cualquiera de estos datos, la semilla no carga.
INSERT INTO creators (uuid,first_name,last_name,display_name,birth_date,email,country_id,
   document_country_code,document_type,document_number,preferred_currency_code,status,activated_at,
   identity_verified_at,identity_verified_by_user_id,identity_document_file_id,created_at)
 SELECT UUID(),'Ana','Torres','anatorres','1998-05-12','ana@ejemplo.test',c.id,'PE','DNI','40000001','PEN','active',NOW(3),
   NOW(3),u.id,f.id,NOW(3)
 FROM countries c, users u, files f
 WHERE c.iso2='PE' AND u.email='aprob@ejemplo.test' AND f.purpose='identity_document';
INSERT INTO creators (uuid,first_name,last_name,display_name,birth_date,email,country_id,
   document_country_code,document_type,document_number,preferred_currency_code,status,activated_at,
   identity_verified_at,identity_verified_by_user_id,identity_document_file_id,created_at)
 SELECT UUID(),'Luis','Vega','luisvega','1995-02-03','luis@ejemplo.test',c.id,'PE','DNI','40000002','PEN','active',NOW(3),
   NOW(3),u.id,f.id,NOW(3)
 FROM countries c, users u, files f
 WHERE c.iso2='PE' AND u.email='aprob@ejemplo.test' AND f.purpose='identity_document';

-- Y la aceptacion de terminos de los dos, registrada por un revisor con la
-- evidencia adjunta: el canal no es 'portal', asi que `ck_terms_acceptances_backing`
-- obliga a que haya quien lo registro y archivo que lo respalde.
INSERT INTO terms_acceptances (uuid,terms_version_id,subject_type,subject_id,channel,
   recorded_by_user_id,evidence_file_id,evidence_note,accepted_at,created_at)
 SELECT UUID(),tv.id,'creator',cr.id,'email',u.id,f.id,'Correo de conformidad archivado',NOW(3),NOW(3)
 FROM terms_versions tv, creators cr, users u, files f
 WHERE tv.code='creator_terms' AND tv.effective_to IS NULL
   AND cr.display_name IN ('anatorres','luisvega')
   AND u.email='aprob@ejemplo.test' AND f.purpose='terms_evidence';

-- Capturado por el operador 1 y verificado por el 2: la segregacion de
-- funciones de `ck_cpm_segregation` (H-11) tambien vale para la semilla. Y
-- verificado anteayer, elegible desde ayer: un medio "verificado hace un
-- instante y ya elegible" no existe con el enfriamiento de BR-FIN-006, y una
-- semilla que describe un estado imposible ensena a los tests a esperar lo
-- imposible.
INSERT INTO creator_payment_methods (uuid,creator_id,owner_type,method_type,country_id,currency_code,
   bank_name,account_type,account_number_encrypted,account_number_masked,account_number_fingerprint,
   holder_name,holder_document_type,holder_document_number,created_by_user_id,status,
   verified_at,verified_by_user_id,eligible_from,is_default,created_at)
 SELECT UUID(),cr.id,'creator','bank_account',cr.country_id,'PEN',
   'BCP','savings','enc:xxxx','****4321',REPEAT('b',64),
   'Ana Torres','DNI','40000001',(SELECT id FROM users ORDER BY id LIMIT 1),'verified',
   NOW(3) - INTERVAL 2 DAY,(SELECT id FROM users ORDER BY id LIMIT 1 OFFSET 1),
   NOW(3) - INTERVAL 1 DAY,1,NOW(3) - INTERVAL 2 DAY
 FROM creators cr WHERE cr.display_name='anatorres';

-- La cobertura de facturacion del pais del cliente.
--
-- El esquema de referencia trae la sociedad CTS-PE pero NINGUNA cobertura, y
-- sin cobertura no hay campana posible fuera de borrador. Se declara aqui, con
-- su fecha de inicio, porque cubrir un pais es una decision con vigencia (4.5)
-- y no un booleano: la campana de la semilla empieza el 2026-09-01 y esta
-- cobertura tiene que estar abierta ese dia.
INSERT INTO legal_entity_countries (legal_entity_id,country_id,coverage_basis,valid_from,created_at)
 SELECT le.id, co.country_id, 'local_entity', '2026-01-01', NOW(3)
   FROM legal_entities le
   JOIN client_organizations co ON co.client_code='CLI-0001'
  WHERE le.code='CTS-PE'
    AND NOT EXISTS (SELECT 1 FROM legal_entity_countries x
                     WHERE x.country_id=co.country_id AND x.valid_to IS NULL)
  LIMIT 1;

-- 7.1: la campana DICE quien la factura. Una `in_progress` sin
-- `billing_legal_entity_id` la rechaza `ck_camp_billing_entity` (`BR-LE-001`),
-- y esta semilla la creaba sin ella desde antes de que la regla existiera.
--
-- Se toma la sociedad que CUBRE el pais del cliente, no «la primera que haya»:
-- si se cogiera cualquiera, la semilla estaria fabricando el caso que 4.5 y
-- `BR-LE-003` existen para impedir --una campana facturada por una sociedad que
-- no cubre ese pais-- y las pruebas correrian sobre datos imposibles.
--
-- Y lleva importe: desde 7.2 `ck_camp_revenue_declarado` exige que una campana
-- fuera de borrador diga si su cero es a proposito. Con el DEFAULT 0 la semilla
-- entera dejaba de cargar, y con ella TODAS las suites. Tercera vez que una
-- restriccion nueva alcanza a la semilla; por eso este fichero se carga primero.
INSERT INTO campaigns (uuid,code,name,client_organization_id,client_brand_id,
   billing_legal_entity_id,currency_code,revenue_amount,is_gratis,
   starts_on,ends_on,status,confirmed_at,created_at)
 SELECT UUID(),'CMP-0001','Campana Demo',co.id,cb.id,
   (SELECT lec.legal_entity_id FROM legal_entity_countries lec
     WHERE lec.country_id = co.country_id
       AND lec.valid_from <= '2026-09-01'
       AND (lec.valid_to IS NULL OR lec.valid_to >= '2026-09-01')
     ORDER BY lec.id LIMIT 1),
   'PEN',12000.0000,0,'2026-09-01','2026-09-30','in_progress',NOW(3),NOW(3)
 FROM client_organizations co JOIN client_brands cb ON cb.client_organization_id=co.id
 WHERE co.client_code='CLI-0001' LIMIT 1;

INSERT INTO campaign_creators (uuid,campaign_id,creator_id,currency_code,agreed_amount,status,invited_at,accepted_at,created_at)
 SELECT UUID(),ca.id,cr.id,'PEN',1500.0000,'accepted',NOW(3),NOW(3),NOW(3)
 FROM campaigns ca, creators cr WHERE ca.code='CMP-0001' AND cr.display_name='anatorres';
INSERT INTO campaign_creators (uuid,campaign_id,creator_id,currency_code,agreed_amount,status,invited_at,accepted_at,created_at)
 SELECT UUID(),ca.id,cr.id,'PEN',900.0000,'accepted',NOW(3),NOW(3),NOW(3)
 FROM campaigns ca, creators cr WHERE ca.code='CMP-0001' AND cr.display_name='luisvega';

-- Plataformas y formatos: los necesitan las pruebas de 2.12 (contenido).
INSERT INTO platforms (code,name,url_pattern,is_active,created_at) VALUES
 ('instagram','Instagram','https://www.instagram.com/p/{id}',1,NOW(3)),
 ('tiktok','TikTok','https://www.tiktok.com/@{user}/video/{id}',1,NOW(3));

INSERT INTO content_formats (platform_id,code,default_permanence_days,is_active,created_at)
 SELECT id,'reel',30,1,NOW(3) FROM platforms WHERE code='instagram';
INSERT INTO content_formats (platform_id,code,default_permanence_days,is_active,created_at)
 SELECT id,'story',1,1,NOW(3) FROM platforms WHERE code='instagram';

INSERT INTO campaign_requirements (campaign_id,content_format_id,quantity,deadline_offset_days,permanence_days,created_at)
 SELECT ca.id,cf.id,2,10,30,NOW(3)
 FROM campaigns ca, content_formats cf WHERE ca.code='CMP-0001' AND cf.code='reel';
