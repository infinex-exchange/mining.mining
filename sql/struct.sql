CREATE ROLE "mining.mining" LOGIN PASSWORD 'password';

create table mining_plans(
    planid bigserial not null primary key,
    name varchar(255) not null,
    months smallint not null,
    unit_name varchar(32) not null,
    total_units int not null,
    sold_units int not null default 0,
    min_ord_units int not null,
    unit_price decimal(65, 32) not null,
    discount_perc_every int,
    discount_max int,
    enabled boolean not null
);

GRANT SELECT, INSERT, UPDATE ON mining_plans TO "mining.mining";
GRANT SELECT, USAGE ON mining_plans_planid_seq TO "mining.mining";

create table plan_assets(
    planid bigint not null,
    assetid varchar(32) not null,
    priority smallint not null,
    unit_avg_revenue decimal(65, 32) not null default 0,
    asset_price_avg decimal(65, 32) not null default 0,
    
    foreign key(planid) references mining_plans(planid),
    unique(planid, assetid)
);

GRANT SELECT, INSERT, UPDATE ON plan_assets TO "mining.mining";











create table mining_total_revenue(
    mtrid bigserial not null primary key,
	assetid varchar(32) not null,
	depo_xid bigint not null,
	time timestamptz not null,
	amount decimal(65, 32) not null,
	units_snap bigint not null,
	
	foreign key(assetid) references assets(assetid),
	foreign key(depo_xid) references wallet_transactions(xid)
);

create table mining_contracts(
	contractid bigserial not null primary key,
	planid bigint not null,
	uid bigint not null,
	units bigint not null,
	price_paid decimal(65, 32) not null,
    payment_xid bigint not null,
	create_time timestamptz not null,
	end_time timestamptz not null,
	
	foreign key(planid) references mining_plans(planid),
	foreign key(uid) references users(uid),
    foreign key(payment_xid) references wallet_transactions(xid)
);

create table mining_contract_revenue_sum(
    contractid bigint not null,
    assetid varchar(32) not null,
    amount decimal(65, 32) not null,
    
    foreign key(contractid) references mining_contracts(contractid),
    foreign key(assetid) references assets(assetid)
);

create table mining_user_revenue(
    uid bigint not null,
    mtrid bigint not null,
    units_owned bigint not null,
    amount decimal(65, 32) not null,
    payout_xid bigint not null,
    
    foreign key(uid) references users(uid),
    foreign key(mtrid) references mining_total_revenue(mtrid),
    foreign key(payout_xid) references wallet_transactions(xid)
);
